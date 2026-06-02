<?php

namespace honchoagency\yesterdaysnews\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use honchoagency\yesterdaysnews\records\VisitRecord;
use honchoagency\yesterdaysnews\YesterdaysNews;
use putyourlightson\blitz\helpers\SiteUriHelper;
use putyourlightson\blitz\models\SiteUriModel;
use yii\base\Component;
use yii\db\Expression;

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
        if (!YesterdaysNews::blitzIsInstalled()) {
            return;
        }

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

            // Each buffer entry tracks both the latest timestamp and how many
            // beacon pings landed between flushes. If the same URL is hit twice
            // before the next flush we increment the in-memory count so the DB
            // upsert can add the full batch count in one go.
            if (isset($pending[$visitKey]) && is_array($pending[$visitKey])) {
                $pending[$visitKey]['ts'] = $now;
                $pending[$visitKey]['count']++;
            } else {
                $pending[$visitKey] = ['ts' => $now, 'count' => 1];
            }

            Craft::$app->getCache()->set(self::PENDING_CACHE_KEY, $pending, 0);
        } finally {
            $mutex->release(self::FLUSH_MUTEX_KEY);
        }
    }

    /**
     * Flush the buffered visits from the Craft cache into the DB.
     *
     * Called by FlushJob on a schedule (every 5 min). Upserts all pending
     * visits into yesterdays_news_visits and clears the buffer only after all
     * rows are written — if the loop fails mid-way, unwritten visits remain in
     * the buffer and will be retried on the next flush.
     *
     * @throws \yii\db\Exception
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

            $db = Craft::$app->getDb();

            foreach ($pending as $visitKey => $entry) {
                // visitKey format: "{siteId}:{uri}"
                $colonPos = strpos($visitKey, ':');
                if ($colonPos === false) {
                    continue;
                }

                // Back-compat: buffer entries written by older versions of the plugin
                // are bare integer timestamps rather than ['ts' => ..., 'count' => ...].
                if (is_int($entry)) {
                    $entry = ['ts' => $entry, 'count' => 1];
                }

                $uri           = substr($visitKey, $colonPos + 1);
                $lastVisitedAt = Db::prepareDateForDb(new \DateTime('@' . $entry['ts']));
                $pendingCount  = max(1, (int) ($entry['count'] ?? 1));

                $db->createCommand()->upsert(
                    '{{%yesterdays_news_visits}}',
                    [
                        'url'           => $uri,
                        'lastVisitedAt' => $lastVisitedAt,
                        'visitCount'    => $pendingCount,
                    ],
                    [
                        'lastVisitedAt' => $lastVisitedAt,
                        // Accumulate visit count across flushes rather than resetting it.
                        'visitCount' => new Expression('[[visitCount]] + ' . $pendingCount),
                    ],
                )->execute();
            }

            Craft::$app->getCache()->delete(self::PENDING_CACHE_KEY);

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
     * @throws \yii\db\Exception
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

        if (!YesterdaysNews::blitzIsInstalled()) {
            $log("Blitz plugin not found — skipping prune.", true);
            return;
        }

        // Age-based template include pruning runs regardless of the page threshold.
        $this->pruneStaleTemplateIncludes($output);

        $settings         = YesterdaysNews::getInstance()->getSettings();
        $threshold        = $settings->threshold;
        $lowVisitCount    = $settings->lowVisitCount;
        $lowVisitThreshold = $settings->lowVisitThreshold;

        $log(sprintf(
            "Thresholds: standard %d seconds (%.1f hours), low-visit (< %d pings) %d seconds (%s).",
            $threshold,
            $threshold / 3600,
            $lowVisitCount,
            $lowVisitThreshold,
            $lowVisitThreshold < 3600
                ? round($lowVisitThreshold / 60) . ' min'
                : round($lowVisitThreshold / 3600, 1) . ' h',
        ));

        if (!$settings->pagePruningEnabled) {
            $log("Page pruning disabled.");
            return;
        }

        $standardCutoff    = new \DateTime();
        $standardCutoff->modify('-' . $threshold . ' seconds');
        $standardCutoffDb  = Db::prepareDateForDb($standardCutoff);

        $lowVisitCutoff    = new \DateTime();
        $lowVisitCutoff->modify('-' . $lowVisitThreshold . ' seconds');
        $lowVisitCutoffDb  = Db::prepareDateForDb($lowVisitCutoff);

        $log(sprintf(
            "Standard cutoff: %s | Low-visit cutoff: %s",
            $standardCutoff->format('Y-m-d H:i:s'),
            $lowVisitCutoff->format('Y-m-d H:i:s'),
        ));

        // A row is stale when it falls under its applicable threshold:
        //  - Low-visit pages (< lowVisitCount pings): use the shorter lowVisitThreshold
        //  - Standard pages (>= lowVisitCount pings): use the normal threshold
        $staleCondition = [
            'or',
            ['and', ['<', 'visitCount', $lowVisitCount], ['<=', 'lastVisitedAt', $lowVisitCutoffDb]],
            ['and', ['>=', 'visitCount', $lowVisitCount], ['<=', 'lastVisitedAt', $standardCutoffDb]],
        ];

        $staleUris = (new Query())
            ->select(['url'])
            ->from('{{%yesterdays_news_visits}}')
            ->where($staleCondition)
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
        VisitRecord::deleteAll($staleCondition);

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
     *
     * @throws \yii\db\Exception
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

        $settings          = YesterdaysNews::getInstance()->getSettings();
        $includeThreshold  = $settings->includeThreshold;
        $entryAgeThreshold = $settings->entryAgeThreshold;

        if (!$settings->includePruningEnabled || empty($settings->includeTemplates)) {
            return;
        }

        if (!YesterdaysNews::blitzIsInstalled()) {
            return;
        }

        $now             = time();
        $includeCutoffDb = Db::prepareDateForDb(new \DateTime('@' . ($now - $includeThreshold)));
        $entryCutoffDb   = Db::prepareDateForDb(new \DateTime('@' . ($now - $entryAgeThreshold)));

        $log(sprintf(
            "Include template prune: entry age > %.0fd, cache age > %.0fh.",
            $entryAgeThreshold / 86400,
            $includeThreshold / 3600,
        ));

        $staleUriMap = [];
        foreach ($this->getIncludeCandidates() as $candidate) {
            $isEntryOld = $candidate['dateUpdated'] !== null && $candidate['dateUpdated'] <= $entryCutoffDb;
            $isCacheOld = $candidate['dateCached'] <= $includeCutoffDb;

            if ($isEntryOld && $isCacheOld) {
                $staleUriMap[$candidate['uri']] = ['index' => $candidate['index'], 'siteId' => $candidate['siteId']];
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
    }

    /**
     * Return all Blitz include candidates for the configured templates.
     *
     * Each candidate has a corresponding blitz_caches record. The returned array
     * includes dateUpdated from the elements table and dateCached from blitz_caches
     * so callers can apply their own staleness thresholds without further queries.
     *
     * @return array<int, array{template: string, index: string, siteId: int, uri: string, entryId: int, dateUpdated: ?string, dateCached: string}>
     * @throws \yii\db\Exception
     */
    public function getIncludeCandidates(): array
    {
        if (!YesterdaysNews::blitzIsInstalled()) {
            return [];
        }

        $includeTemplates = YesterdaysNews::getInstance()->getSettings()->includeTemplates;

        if (empty($includeTemplates)) {
            return [];
        }

        $candidates = [];

        foreach ($includeTemplates as $template => $entryIdKey) {
            $includeRows = (new Query())
                ->select(['index', 'siteId', 'params'])
                ->from('{{%blitz_includes}}')
                ->where(['template' => $template])
                ->all();

            if (empty($includeRows)) {
                continue;
            }

            // Parse params JSON → entryId for each include row.
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

            // Build URI map and fetch dateCached for each include from blitz_caches.
            $uriToIndex = [];
            foreach ($indexToEntryId as $index => $entryId) {
                $uri              = '_cached_include_' . $index . '?p=_cached_include_' . $index;
                $uriToIndex[$uri] = $index;
            }

            $cacheData = (new Query())
                ->select(['uri', 'dateCached'])
                ->from('{{%blitz_caches}}')
                ->where(['uri' => array_keys($uriToIndex)])
                ->indexBy('uri')
                ->all();

            // Fetch dateUpdated for all related entries in one query.
            $elementData = (new Query())
                ->select(['id', 'dateUpdated'])
                ->from('{{%elements}}')
                ->where(['id' => array_values($indexToEntryId)])
                ->indexBy('id')
                ->all();

            foreach ($uriToIndex as $uri => $index) {
                $cacheRow = $cacheData[$uri] ?? null;
                if ($cacheRow === null) {
                    continue; // not yet cached by Blitz
                }

                $entryId     = $indexToEntryId[$index];
                $elementRow  = $elementData[$entryId] ?? null;

                $candidates[] = [
                    'template'    => $template,
                    'index'       => $index,
                    'siteId'      => $indexToSiteId[$index],
                    'uri'         => $uri,
                    'entryId'     => $entryId,
                    'dateUpdated' => $elementRow['dateUpdated'] ?? null,
                    'dateCached'  => $cacheRow['dateCached'],
                ];
            }
        }

        return $candidates;
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
     *
     * @throws \yii\db\Exception
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
     * @throws \yii\db\Exception
     */
    public function syncFromBlitz(?callable $output = null): int
    {
        $log = function (string $msg) use ($output): void {
            Craft::info($msg, __METHOD__);
            if ($output !== null) {
                $output($msg . PHP_EOL);
            }
        };

        if (!YesterdaysNews::blitzIsInstalled()) {
            $log('Blitz plugin not found — skipping sync.');
            return 0;
        }

        // Build a lookup set of already-tracked URIs in batches to avoid loading
        // the whole table into memory at once.
        $trackedUrls = [];
        foreach (VisitRecord::find()->select('url')->asArray()->batch(500) as $batch) {
            foreach ($batch as $row) {
                $trackedUrls[$row['url']] = true;
            }
        }

        // Process Blitz-cached URIs in batches, inserting untracked ones as we go.
        // Avoids a cross-table JOIN between tables that may have different collations.
        // upsert(..., false) → INSERT IGNORE on MySQL: skips rows that were visited by
        // a real user between our snapshot and this insert loop.
        $db = Craft::$app->getDb();
        $inserted = 0;

        foreach (\putyourlightson\blitz\records\CacheRecord::find()
            ->select(['uri', 'dateCached'])
            ->where(['IS NOT', 'dateCached', null])
            ->asArray()
            ->batch(500) as $batch) {
            foreach ($batch as $row) {
                if (isset($trackedUrls[$row['uri']]) || str_starts_with($row['uri'], '_cached_include_')) {
                    continue;
                }

                $db->createCommand()->upsert('{{%yesterdays_news_visits}}', [
                    'url'           => $row['uri'],
                    'lastVisitedAt' => $row['dateCached'],
                ], false)->execute();

                $trackedUrls[$row['uri']] = true;
                $inserted++;
            }
        }

        if ($inserted === 0) {
            $log('Sync: no untracked Blitz URIs found.');
            return 0;
        }

        $log(sprintf('Sync complete: %d row(s) inserted.', $inserted));

        return $inserted;
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
