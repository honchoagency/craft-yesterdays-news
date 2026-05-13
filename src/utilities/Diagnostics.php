<?php

namespace honchoagency\yesterdaysnews\utilities;

use Craft;
use craft\base\Utility;
use craft\db\Query;
use craft\helpers\Db;
use craft\web\View;
use honchoagency\yesterdaysnews\YesterdaysNews;

/**
 * Diagnostics utility — CP table view of the yesterdays_news_visits DB table,
 * annotated with prune status for each row.
 */
class Diagnostics extends Utility
{
    public static function displayName(): string
    {
        return "Yesterday's News";
    }

    public static function id(): string
    {
        return 'yesterdays-news-diagnostics';
    }

    public static function icon(): ?string
    {
        return 'newspaper';
    }

    public static function contentHtml(): string
    {
        $plugin    = YesterdaysNews::getInstance();
        $threshold = $plugin->getSettings()->threshold;

        $cutoff      = null;
        $cutoffLocal = null; // formatted in app timezone
        $cutoffUtc   = null; // formatted in UTC
        $cutoffTzAbbr = null; // e.g. 'EST'
        $appTimezone = Craft::$app->getTimeZone();

        if ($threshold > 0) {
            $cutoff = new \DateTime('now', new \DateTimeZone('UTC'));
            $cutoff->modify('-' . $threshold . ' seconds');
            $cutoffUtc = $cutoff->format('Y-m-d H:i');

            $local = clone $cutoff;
            $local->setTimezone(new \DateTimeZone($appTimezone));
            $cutoffLocal  = $local->format('Y-m-d H:i');
            $cutoffTzAbbr = $local->format('T'); // EST, EDT, etc.
        }

        $rawRows = (new Query())
            ->select(['url', 'lastVisitedAt'])
            ->from('{{%yesterdays_news_visits}}')
            ->orderBy(['lastVisitedAt' => SORT_ASC])
            ->all();

        $now      = time();
        $rows     = [];
        $staleCount = 0;

        foreach ($rawRows as $raw) {
            // lastVisitedAt is stored in UTC; parse it explicitly to avoid
            // strtotime() misinterpreting it as the app's local timezone.
            $lastVisitedTs = (new \DateTime($raw['lastVisitedAt'], new \DateTimeZone('UTC')))->getTimestamp();
            $ageSeconds    = $now - $lastVisitedTs;
            $isStale       = $threshold > 0
                && $cutoff !== null
                && $raw['lastVisitedAt'] <= $cutoff->format('Y-m-d H:i:s');

            if ($isStale) {
                $staleCount++;
            }

            $rows[] = [
                'url'            => $raw['url'],
                'lastVisitedAt'  => $raw['lastVisitedAt'],
                'ageSeconds'     => $ageSeconds,
                'ageHuman'       => self::formatAge($ageSeconds),
                'isStale'        => $isStale,
                'pruneInSeconds' => (!$isStale && $threshold > 0) ? ($threshold - $ageSeconds) : null,
                'pruneInHuman'   => (!$isStale && $threshold > 0) ? self::formatAge($threshold - $ageSeconds) : null,
            ];
        }

        // --- Cached include candidates ---
        $settings          = $plugin->getSettings();
        $includeTemplates  = $settings->includeTemplates;
        $includeThreshold  = $settings->includeThreshold;
        $entryAgeThreshold = $settings->entryAgeThreshold;

        $includeRows      = [];
        $includeReadyCount = 0;

        if (!empty($includeTemplates) && $includeThreshold > 0 && $entryAgeThreshold > 0) {
            $includeCutoff = (new \DateTime('now', new \DateTimeZone('UTC')))->modify('-' . $includeThreshold . ' seconds');
            $entryCutoff   = (new \DateTime('now', new \DateTimeZone('UTC')))->modify('-' . $entryAgeThreshold . ' seconds');
            $includeCutoffStr = $includeCutoff->format('Y-m-d H:i:s');
            $entryCutoffStr   = $entryCutoff->format('Y-m-d H:i:s');

            foreach ($includeTemplates as $template => $entryIdKey) {
                $blitzRows = (new Query())
                    ->select(['index', 'siteId', 'params'])
                    ->from('{{%blitz_includes}}')
                    ->where(['template' => $template])
                    ->all();

                if (empty($blitzRows)) {
                    continue;
                }

                // Parse params JSON to extract entry IDs.
                $indexToEntryId = [];
                $indexToSiteId  = [];
                foreach ($blitzRows as $row) {
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

                // Fetch dateUpdated for all related entries in one query.
                $elementData = (new Query())
                    ->select(['id', 'dateUpdated'])
                    ->from('{{%elements}}')
                    ->where(['id' => array_values($indexToEntryId)])
                    ->indexBy('id')
                    ->all();

                // Fetch dateCached from blitz_caches for all URIs in one query.
                $uriMap = [];
                foreach ($indexToEntryId as $index => $entryId) {
                    $uri = '_cached_include_' . $index . '?p=_cached_include_' . $index;
                    $uriMap[$uri] = ['index' => $index, 'entryId' => $entryId];
                }

                $cacheData = (new Query())
                    ->select(['uri', 'dateCached'])
                    ->from('{{%blitz_caches}}')
                    ->where(['uri' => array_keys($uriMap)])
                    ->indexBy('uri')
                    ->all();

                foreach ($uriMap as $uri => $info) {
                    $entryId    = $info['entryId'];
                    $index      = $info['index'];
                    $elementRow = $elementData[$entryId] ?? null;
                    $cacheRow   = $cacheData[$uri] ?? null;
                    $dateCached = $cacheRow['dateCached'] ?? null;

                    if ($dateCached === null) {
                        continue; // No cache record, skip this include
                    }

                    $dateUpdated = $elementRow['dateUpdated'] ?? null;
                    $entryAgeSeconds = $dateUpdated !== null
                        ? $now - (new \DateTime($dateUpdated, new \DateTimeZone('UTC')))->getTimestamp()
                        : null;
                    $isEntryOld = $dateUpdated !== null && $dateUpdated <= $entryCutoffStr;

                    $cacheAgeSeconds = $dateCached !== null
                        ? $now - (new \DateTime($dateCached, new \DateTimeZone('UTC')))->getTimestamp()
                        : null;
                    $isCacheOld = $dateCached !== null && $dateCached <= $includeCutoffStr;

                    $isReadyToPrune = $isEntryOld && $isCacheOld;
                    if ($isReadyToPrune) {
                        $includeReadyCount++;
                    }

                    $includeRows[] = [
                        'template'       => $template,
                        'entryId'        => $entryId,
                        'uri'            => $uri,
                        'dateUpdated'    => $dateUpdated,
                        'entryAgeHuman'  => $entryAgeSeconds !== null ? self::formatAge($entryAgeSeconds) : null,
                        'isEntryOld'     => $isEntryOld,
                        'dateCached'     => $dateCached,
                        'cacheAgeHuman'  => $cacheAgeSeconds !== null ? self::formatAge($cacheAgeSeconds) : null,
                        'isCacheOld'     => $isCacheOld,
                        'isReadyToPrune' => $isReadyToPrune,
                    ];
                }
            }

            // Sort: ready-to-prune first, then by template, then by cache age desc.
            usort($includeRows, function (array $a, array $b): int {
                if ($b['isReadyToPrune'] !== $a['isReadyToPrune']) {
                    return $b['isReadyToPrune'] <=> $a['isReadyToPrune'];
                }
                $templateCmp = strcmp($a['template'], $b['template']);
                if ($templateCmp !== 0) {
                    return $templateCmp;
                }
                return ($b['cacheAgeHuman'] ?? '') <=> ($a['cacheAgeHuman'] ?? '');
            });
        }

        return Craft::$app->getView()->renderTemplate('yesterdays-news/_diagnostics', [
            'rows'               => $rows,
            'threshold'          => $threshold,
            'thresholdHuman'     => $threshold > 0 ? self::formatAge($threshold) : 'disabled',
            'cutoffLocal'        => $cutoffLocal,
            'cutoffUtc'          => $cutoffUtc,
            'cutoffTzAbbr'       => $cutoffTzAbbr,
            'appTimezone'        => $appTimezone,
            'totalCount'         => count($rows),
            'staleCount'         => $staleCount,
            'freshCount'         => count($rows) - $staleCount,
            'pendingCount'       => $plugin->visits->getPendingCount(),
            'includeRows'        => $includeRows,
            'includeReadyCount'  => $includeReadyCount,
            'includeTotalCount'  => count($includeRows),
            'includeThreshold'   => $includeThreshold,
            'entryAgeThreshold'  => $entryAgeThreshold,
        ], View::TEMPLATE_MODE_CP);
    }

    private static function formatAge(int $seconds): string
    {
        if ($seconds < 180)    return $seconds . 's';                      // < 3 min  → seconds
        if ($seconds < 10800)  return round($seconds / 60) . 'm';          // < 3 hr   → minutes
        if ($seconds < 259200) return round($seconds / 3600, 1) . 'h';    // < 3 days → hours
        return round($seconds / 86400, 1) . 'd';                           // ≥ 3 days → days
    }
}
