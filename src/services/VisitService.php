<?php

namespace honchoagency\yesterdaysnews\services;

use Craft;
use craft\helpers\Db;
use honchoagency\yesterdaysnews\records\VisitRecord;
use honchoagency\yesterdaysnews\YesterdaysNews;
use putyourlightson\blitz\helpers\SiteUriHelper;
use putyourlightson\blitz\models\SiteUriModel;
use yii\base\Component;

/**
 * Visit Service
 *
 * Write path: bufferVisit() stores visits in Craft cache (Redis) — O(1), no DB writes on the hot path.
 * Flush path: flushBufferToDb() upserts buffered visits into the DB — called every 5 min by FlushJob.
 * Prune path: pruneStaleUrls() removes stale URLs from both Blitz cache and our DB table.
 */
class VisitService extends Component
{
    private const PENDING_CACHE_KEY = 'yn_pending_visits';
    private const FLUSH_MUTEX_KEY = 'yn_flush';

    /**
     * Buffer a page visit in the Craft cache.
     *
     * Called on every JS beacon POST. Must be fast — no DB write occurs here.
     * The raw path from window.location.pathname is normalised via Blitz's own
     * SiteUriHelper::getSiteUriFromUrl() so the stored key exactly matches
     * what Blitz stores in blitz_caches.uri.
     */
    public function bufferVisit(string $rawPath): void
    {
        $siteUri = $this->normalisePath($rawPath);

        if ($siteUri === null) {
            return;
        }

        $visitKey = $siteUri->siteId . ':' . $siteUri->uri;
        $now = time();

        // Mutex-guarded update of the pending visits array in the Yii cache.
        // We acquire briefly to read-modify-write the single cache key.
        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire(self::FLUSH_MUTEX_KEY, 2)) {
            // Could not acquire mutex — skip silently rather than block the request.
            return;
        }

        try {
            $pending = Craft::$app->getCache()->get(self::PENDING_CACHE_KEY);
            if (!is_array($pending)) {
                $pending = [];
            }
            $pending[$visitKey] = $now;
            Craft::$app->getCache()->set(self::PENDING_CACHE_KEY, $pending, 0);
        } finally {
            $mutex->release(self::FLUSH_MUTEX_KEY);
        }
    }

    /**
     * Flush the buffered visits from the Craft cache into the DB.
     *
     * Called by FlushJob on a schedule (every 5 min). Upserts all pending
     * visits into yesterdays_news_visits and clears the buffer.
     */
    public function flushBufferToDb(): void
    {
        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire(self::FLUSH_MUTEX_KEY, 10)) {
            Craft::warning("Yesterday's News: could not acquire flush mutex.", __METHOD__);
            return;
        }

        try {
            $pending = Craft::$app->getCache()->get(self::PENDING_CACHE_KEY);

            if (!is_array($pending) || empty($pending)) {
                return;
            }

            // Clear the buffer first so we don't re-process visits on failure.
            Craft::$app->getCache()->delete(self::PENDING_CACHE_KEY);

            $db = Craft::$app->getDb();

            foreach ($pending as $visitKey => $timestamp) {
                // visitKey format: "{siteId}:{uri}"
                $colonPos = strpos($visitKey, ':');
                if ($colonPos === false) {
                    continue;
                }

                $uri = substr($visitKey, $colonPos + 1);
                $lastVisitedAt = Db::prepareDateForDb(new \DateTime('@' . $timestamp));

                $db->createCommand()->upsert(
                    '{{%yesterdays_news_visits}}',
                    [
                        'url'           => $uri,
                        'lastVisitedAt' => $lastVisitedAt,
                    ],
                    [
                        'lastVisitedAt' => $lastVisitedAt,
                    ],
                )->execute();
            }

            Craft::info(
                sprintf("Yesterday's News: flushed %d visit(s) to DB.", count($pending)),
                __METHOD__,
            );
        } finally {
            $mutex->release(self::FLUSH_MUTEX_KEY);
        }
    }

    /**
     * Prune stale URLs from the Blitz cache and our visits table.
     *
     * Called by PruneJob (no $output) and the CLI controller (passes stdout callable).
     *
     * @param callable|null $output  Optional fn(string): void for CLI stdout lines.
     */
    public function pruneStaleUrls(?callable $output = null): void
    {
        $log = function (string $msg, bool $warn = false) use ($output): void {
            if ($warn) {
                Craft::warning($msg, __METHOD__);
            } else {
                Craft::info($msg, __METHOD__);
            }
            if ($output !== null) {
                $output($msg . PHP_EOL);
            }
        };

        // Gracefully no-op if Blitz is not installed.
        if (Craft::$app->getPlugins()->getPlugin('blitz') === null) {
            $log("Blitz plugin not found — skipping prune.", true);
            return;
        }

        // Age-based template include pruning runs regardless of the page threshold.
        $this->pruneStaleTemplateIncludes($output);

        $threshold = YesterdaysNews::getInstance()->getSettings()->threshold;
        $log(sprintf("Threshold: %d seconds (%.1f hours).", $threshold, $threshold / 3600));

        if ($threshold <= 0) {
            $log("Pruning disabled (threshold <= 0).");
            return;
        }

        $cutoff = new \DateTime();
        $cutoff->modify('-' . $threshold . ' seconds');
        $cutoffDb = Db::prepareDateForDb($cutoff);
        $log(sprintf("Cutoff: visits older than %s will be removed.", $cutoff->format('Y-m-d H:i:s')));

        $staleUris = (new \craft\db\Query())
            ->select(['url'])
            ->from('{{%yesterdays_news_visits}}')
            ->where(['<=', 'lastVisitedAt', $cutoffDb])
            ->column();

        if (empty($staleUris)) {
            $log("No stale URLs found.");
            return;
        }

        $log(sprintf("Found %d stale URL(s):", count($staleUris)));
        foreach ($staleUris as $uri) {
            $log("  - /$uri");
        }

        // Build absolute URLs — Blitz's getSiteUriFromUrl() needs them to match
        // against site base URLs and strip the base path correctly.
        $primarySite = Craft::$app->getSites()->getPrimarySite();
        $baseUrl = rtrim($primarySite->getBaseUrl(), '/');

        $absoluteUrls = array_map(
            fn(string $uri): string => $baseUrl . '/' . ltrim($uri, '/'),
            $staleUris,
        );

        $siteUris = SiteUriHelper::getSiteUrisFromUrls($absoluteUrls);

        if (!empty($siteUris)) {
            /** @var \putyourlightson\blitz\Blitz $blitz */
            $blitz = Craft::$app->getPlugins()->getPlugin('blitz');

            // clear pages from Blitz static cache
            $log(sprintf("Clearing Blitz static cache for %d URI(s)...", count($siteUris)));
            $blitz->clearCache->clearUris($siteUris);

            // flush pages from Blitz database
            $log(sprintf("Flushing Blitz DB cache records for %d URI(s)...", count($siteUris)));
            $blitz->flushCache->flushUris($siteUris);

            // purge pages from reverse proxy caches
            $log(sprintf("Purging reverse proxy cache for %d URI(s)...", count($siteUris)));
            $blitz->cachePurger->purgeUris($siteUris);
        } else {
            $log("No matching Blitz cache URIs found (URLs may not be cached).");
        }

        $log(sprintf("Deleting %d row(s) from DB visits table...", count($staleUris)));
        VisitRecord::deleteAll(['<=', 'lastVisitedAt', $cutoffDb]);

        $log("Prune complete.");
    }

    /**
     * Prune stale Blitz cached includes for the configured include templates.
     *
     * An include is pruned when BOTH conditions are met:
     *   1. The related entry has not been updated in more than $settings->entryAgeThreshold seconds.
     *   2. The blitz_caches record is older than $settings->includeThreshold seconds.
     *
     * This means fresh/recently-updated articles always stay cached, while old
     * articles get a rolling cache window defined by $includeThreshold.
     */
    public function pruneStaleTemplateIncludes(?callable $output = null): void
    {
        $log = function (string $msg, bool $warn = false) use ($output): void {
            if ($warn) {
                Craft::warning($msg, __METHOD__);
            } else {
                Craft::info($msg, __METHOD__);
            }
            if ($output !== null) {
                $output($msg . PHP_EOL);
            }
        };

        $settings = YesterdaysNews::getInstance()->getSettings();
        $includeTemplates  = $settings->includeTemplates;
        $includeThreshold  = $settings->includeThreshold;
        $entryAgeThreshold = $settings->entryAgeThreshold;

        if (empty($includeTemplates) || $includeThreshold <= 0 || $entryAgeThreshold <= 0) {
            return;
        }

        if (Craft::$app->getPlugins()->getPlugin('blitz') === null) {
            return;
        }

        $now = time();
        $includeCutoffDb = Db::prepareDateForDb(new \DateTime('@' . ($now - $includeThreshold)));
        $entryCutoffDb   = Db::prepareDateForDb(new \DateTime('@' . ($now - $entryAgeThreshold)));

        $log(sprintf(
            "Include template prune: entry age > %.0fd, cache age > %.0fh.",
            $entryAgeThreshold / 86400,
            $includeThreshold / 3600,
        ));

        // Collect stale (uri → [index, siteId]) across all configured templates.
        $staleUriMap    = []; // uri → ['index' => …, 'siteId' => …]
        $staleIndexes   = [];

        foreach ($includeTemplates as $template => $entryIdKey) {
            // 1. Get all cached includes for this template.
            $includeRows = (new \craft\db\Query())
                ->select(['index', 'siteId', 'params'])
                ->from('{{%blitz_includes}}')
                ->where(['template' => $template])
                ->all();

            if (empty($includeRows)) {
                continue;
            }

            // 2. Parse params JSON → entry ID; build index → entryId map.
            $indexToEntryId = [];
            $indexToSiteId  = [];
            foreach ($includeRows as $row) {
                $params  = json_decode($row['params'], true);
                $entryId = isset($params[$entryIdKey]) ? (int) $params[$entryIdKey] : null;
                if ($entryId !== null) {
                    $indexToEntryId[(string) $row['index']] = $entryId;
                    $indexToSiteId[(string) $row['index']]  = (int) $row['siteId'];
                }
            }

            if (empty($indexToEntryId)) {
                continue;
            }

            // 3. Find which of those entries have not been updated recently.
            $oldEntryIds = (new \craft\db\Query())
                ->select(['id'])
                ->from('{{%elements}}')
                ->where(['id' => array_values($indexToEntryId)])
                ->andWhere(['<=', 'dateUpdated', $entryCutoffDb])
                ->column();

            $oldEntryIds = array_map('intval', $oldEntryIds);

            if (empty($oldEntryIds)) {
                continue;
            }

            // 4. Keep only indexes whose entry is old.
            $candidateUriMap = [];
            foreach ($indexToEntryId as $index => $entryId) {
                if (in_array($entryId, $oldEntryIds, true)) {
                    $uri = '_cached_include_' . $index . '?p=_cached_include_' . $index;
                    $candidateUriMap[$uri] = ['index' => $index, 'siteId' => $indexToSiteId[$index]];
                }
            }

            if (empty($candidateUriMap)) {
                continue;
            }

            // 5. Of those, find which blitz_caches records are also old enough.
            $staleUris = (new \craft\db\Query())
                ->select(['uri'])
                ->from('{{%blitz_caches}}')
                ->where(['uri' => array_keys($candidateUriMap)])
                ->andWhere(['<=', 'dateCached', $includeCutoffDb])
                ->column();

            foreach ($staleUris as $uri) {
                $staleUriMap[$uri] = $candidateUriMap[$uri];
                $staleIndexes[]    = $candidateUriMap[$uri]['index'];
            }
        }

        if (empty($staleUriMap)) {
            $log("No stale template includes found.");
            return;
        }

        $log(sprintf("Found %d stale template include(s):", count($staleUriMap)));

        /** @var \putyourlightson\blitz\Blitz $blitz */
        $blitz    = Craft::$app->getPlugins()->getPlugin('blitz');
        $siteUris = [];
        foreach ($staleUriMap as $uri => $entry) {
            $log("  - /$uri (site {$entry['siteId']})");
            $siteUris[] = new SiteUriModel(['siteId' => $entry['siteId'], 'uri' => $uri]);
        }

        $blitz->clearCache->clearUris($siteUris);
        $blitz->flushCache->flushUris($siteUris);
        $blitz->cachePurger->purgeUris($siteUris);

        // $deleted = Craft::$app->getDb()->createCommand()
        //     ->delete('{{%blitz_includes}}', ['index' => $staleIndexes])
        //     ->execute();

        // $log(sprintf(
        //     "Include template prune complete: %d cleared, %d blitz_includes row(s) deleted.",
        //     count($siteUris),
        //     $deleted,
        // ));
    }

    /**
     * Return the number of visits currently buffered in the Craft cache awaiting flush.
     */
    public function getPendingCount(): int
    {
        $pending = Craft::$app->getCache()->get(self::PENDING_CACHE_KEY);
        return is_array($pending) ? count($pending) : 0;
    }

    /**
     * Clear all visit data — both the DB table and the in-memory cache buffer.
     * Returns the number of DB rows deleted.
     */
    public function clearAll(): int
    {
        $count = VisitRecord::find()->count();

        VisitRecord::deleteAll();
        Craft::$app->getCache()->delete(self::PENDING_CACHE_KEY);

        return (int) $count;
    }

    /**
     * Sync Blitz-cached pages that have no YN visit record into the visits table.
     *
     * Bots don't execute JS, so Blitz may cache thousands of URLs that the YN
     * beacon never fires for. This method copies those missing rows into
     * yesterdays_news_visits using dateCached as lastVisitedAt, so the normal
     * prune cycle can then expire them like any other stale page.
     *
     * Uses INSERT (not upsert) — existing rows (real human visits) are untouched.
     *
     * @param callable|null $output  Optional fn(string): void for CLI stdout lines.
     * @return int  Number of rows inserted.
     */
    public function syncFromBlitz(?callable $output = null): int
    {
        $log = function (string $msg) use ($output): void {
            Craft::info($msg, __METHOD__);
            if ($output !== null) {
                $output($msg . PHP_EOL);
            }
        };

        if (Craft::$app->getPlugins()->getPlugin('blitz') === null) {
            $log('Blitz plugin not found — skipping sync.');
            return 0;
        }

        // Fetch already-tracked URIs via ActiveRecord, then diff in PHP.
        // Avoids a cross-table JOIN between tables with different collations.
        $trackedUrls = array_flip(
            VisitRecord::find()->select('url')->column()
        );

        // Fetch all Blitz-cached URIs via CacheRecord (no JOIN).
        $blitzRows = \putyourlightson\blitz\records\CacheRecord::find()
            ->select(['uri', 'dateCached'])
            ->where(['IS NOT', 'dateCached', null])
            ->asArray()
            ->all();

        $rows = array_values(array_filter(
            $blitzRows,
            fn(array $row) => !array_key_exists($row['uri'], $trackedUrls)
                && !str_starts_with($row['uri'], '_cached_include_'),
        ));

        if (empty($rows)) {
            $log('Sync: no untracked Blitz URIs found.');
            return 0;
        }

        $log(sprintf('Sync: inserting %d untracked Blitz URI(s) into visits table...', count($rows)));

        // upsert(..., false) → INSERT IGNORE on MySQL: skips rows that have been
        // visited by a real user between our snapshot and this insert loop.
        $db = Craft::$app->getDb();
        foreach ($rows as $row) {
            $db->createCommand()->upsert('{{%yesterdays_news_visits}}', [
                'url'           => $row['uri'],
                'lastVisitedAt' => $row['dateCached'],
            ], false)->execute();
        }

        $log(sprintf('Sync complete: %d row(s) inserted.', count($rows)));

        return count($rows);
    }

    /**
     * Normalise a raw path from window.location.pathname into a SiteUriModel
     * using Blitz's own helper, so the stored URI exactly matches blitz_caches.uri.
     */
    private function normalisePath(string $rawPath): ?\putyourlightson\blitz\models\SiteUriModel
    {
        // Strip everything except the path (and optionally query string).
        $path = parse_url($rawPath, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        // Guard against suspiciously long paths.
        if (strlen($path) > 500) {
            return null;
        }

        $primarySite = Craft::$app->getSites()->getPrimarySite();
        $baseUrl = rtrim($primarySite->getBaseUrl(), '/');
        $absoluteUrl = $baseUrl . '/' . ltrim($path, '/');

        return SiteUriHelper::getSiteUriFromUrl($absoluteUrl);
    }
}
