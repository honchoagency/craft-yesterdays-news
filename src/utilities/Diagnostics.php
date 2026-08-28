<?php

namespace honchoagency\yesterdaysnews\utilities;

use Craft;
use craft\base\Utility;
use craft\db\Query;
use craft\models\Site;
use craft\web\View;
use honchoagency\yesterdaysnews\web\assets\cp\CPAsset;
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
        $iconPath = Craft::getAlias('@honchoagency/yesterdaysnews/icon-mask.svg');

        if (!is_string($iconPath)) {
            return null;
        }

        return $iconPath;
    }

    public static function contentHtml(): string
    {
        Craft::$app->getView()->registerAssetBundle(CPAsset::class);

        $site = self::requestedSite();

        $plugin            = YesterdaysNews::getInstance();
        $settings          = $plugin->getSettings();
        $threshold         = $settings->threshold;
        $lowVisitCount     = $settings->lowVisitCount;
        $lowVisitThreshold = $settings->lowVisitThreshold;

        $cutoff = null;

        if ($settings->pagePruningEnabled) {
            $cutoff = new \DateTime('now', new \DateTimeZone('UTC'));
            $cutoff->modify('-' . $threshold . ' seconds');
        }

        $lowVisitCutoffStr = $settings->pagePruningEnabled
            ? (new \DateTime('now', new \DateTimeZone('UTC')))
                ->modify('-' . $lowVisitThreshold . ' seconds')
                ->format('Y-m-d H:i:s')
            : null;

        $rawRows = (new Query())
            ->select(['url', 'lastVisitedAt', 'visitCount'])
            ->from('{{%yesterdays_news_visits}}')
            ->where(['siteId' => $site->id])
            ->orderBy(['lastVisitedAt' => SORT_ASC])
            ->all();

        $now      = time();
        $rows     = [];
        $staleCount = 0;

        foreach ($rawRows as $raw) {
            // lastVisitedAt is stored in UTC; parse it explicitly to avoid
            // strtotime() misinterpreting it as the app's local timezone.
            $lastVisitedTs  = (new \DateTime($raw['lastVisitedAt'], new \DateTimeZone('UTC')))->getTimestamp();
            $ageSeconds     = $now - $lastVisitedTs;
            $visitCount     = (int) $raw['visitCount'];
            $isLowVisit     = $visitCount < $lowVisitCount;

            // Stale threshold depends on whether the page has enough visits to
            // be treated as real human traffic.
            $applicableThreshold = $isLowVisit ? $lowVisitThreshold : $threshold;
            $applicableCutoffStr = $isLowVisit
                ? $lowVisitCutoffStr
                : ($cutoff !== null ? $cutoff->format('Y-m-d H:i:s') : null);

            $isStale = $settings->pagePruningEnabled
                && $applicableCutoffStr !== null
                && $raw['lastVisitedAt'] <= $applicableCutoffStr;

            if ($isStale) {
                $staleCount++;
            }

            $rows[] = [
                'url'            => $raw['url'],
                'lastVisitedAt'  => $raw['lastVisitedAt'],
                'ageSeconds'     => $ageSeconds,
                'ageHuman'       => self::formatAge($ageSeconds),
                'visitCount'     => $visitCount,
                'isLowVisit'     => $isLowVisit,
                'isStale'        => $isStale,
                'pruneInSeconds' => (!$isStale && $settings->pagePruningEnabled) ? ($applicableThreshold - $ageSeconds) : null,
                'pruneInHuman'   => (!$isStale && $settings->pagePruningEnabled) ? self::formatAge($applicableThreshold - $ageSeconds) : null,
            ];
        }

        // Prune/flush/sync/clear all operate across every site, not just the
        // one selected in the switcher — count stale rows across the whole
        // table so the button labels reflect what a click will actually do.
        $globalStaleCount = 0;

        if ($settings->pagePruningEnabled) {
            $globalStaleCount = (int) (new Query())
                ->from('{{%yesterdays_news_visits}}')
                ->where([
                    'or',
                    ['and', ['<', 'visitCount', $lowVisitCount], ['<=', 'lastVisitedAt', $lowVisitCutoffStr]],
                    ['and', ['>=', 'visitCount', $lowVisitCount], ['<=', 'lastVisitedAt', $cutoff->format('Y-m-d H:i:s')]],
                ])
                ->count();
        }

        // --- Cached include candidates ---
        $includeThreshold  = $settings->includeThreshold;
        $entryAgeThreshold = $settings->entryAgeThreshold;

        $includeRows       = [];
        $includeReadyCount = 0;
        $globalIncludeReadyCount = 0;

        if ($settings->includePruningEnabled && !empty($settings->includeTemplates)) {
            $includeCutoffStr = (new \DateTime('now', new \DateTimeZone('UTC')))
                ->modify('-' . $includeThreshold . ' seconds')
                ->format('Y-m-d H:i:s');
            $entryCutoffStr = (new \DateTime('now', new \DateTimeZone('UTC')))
                ->modify('-' . $entryAgeThreshold . ' seconds')
                ->format('Y-m-d H:i:s');

            // Prune is global — count ready-to-prune includes across every
            // site for the button label, separately from the site-filtered
            // rows built below for the table.
            foreach ($plugin->visits->getIncludeCandidates() as $candidate) {
                $isEntryOld = $candidate['dateUpdated'] !== null && $candidate['dateUpdated'] <= $entryCutoffStr;
                $isCacheOld = $candidate['dateCached'] <= $includeCutoffStr;

                if ($isEntryOld && $isCacheOld) {
                    $globalIncludeReadyCount++;
                }
            }

            foreach ($plugin->visits->getIncludeCandidates($site->id) as $candidate) {
                $dateUpdated     = $candidate['dateUpdated'];
                $dateCached      = $candidate['dateCached'];
                $entryAgeSeconds = $dateUpdated !== null
                    ? $now - (new \DateTime($dateUpdated, new \DateTimeZone('UTC')))->getTimestamp()
                    : null;
                $cacheAgeSeconds = $now - (new \DateTime($dateCached, new \DateTimeZone('UTC')))->getTimestamp();
                $isEntryOld      = $dateUpdated !== null && $dateUpdated <= $entryCutoffStr;
                $isCacheOld      = $dateCached <= $includeCutoffStr;
                $isReadyToPrune  = $isEntryOld && $isCacheOld;

                if ($isReadyToPrune) {
                    $includeReadyCount++;
                }

                $includeRows[] = [
                    'template'       => $candidate['template'],
                    'entryId'        => $candidate['entryId'],
                    'uri'            => $candidate['uri'],
                    'dateUpdated'    => $dateUpdated,
                    'entryAgeHuman'  => $entryAgeSeconds !== null ? self::formatAge($entryAgeSeconds) : null,
                    'isEntryOld'     => $isEntryOld,
                    'dateCached'     => $dateCached,
                    'cacheAgeHuman'  => self::formatAge($cacheAgeSeconds),
                    'isCacheOld'     => $isCacheOld,
                    'isReadyToPrune' => $isReadyToPrune,
                ];
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
            'blitzIsInstalled'      => YesterdaysNews::blitzIsInstalled(),
            'site'                  => $site,
            'siteId'                => $site->id,
            'rows'                  => $rows,
            'threshold'             => $threshold,
            'lowVisitCount'         => $lowVisitCount,
            'lowVisitThreshold'     => $lowVisitThreshold,
            'lowVisitThresholdHuman' => self::formatAge($lowVisitThreshold),
            'pagePruningEnabled'    => $settings->pagePruningEnabled,
            'thresholdHuman'        => $settings->pagePruningEnabled ? self::formatAge($threshold) : 'disabled',
            'totalCount'            => count($rows),
            'staleCount'            => $staleCount,
            'freshCount'            => count($rows) - $staleCount,
            'pendingCount'          => $plugin->visits->getPendingCount($site->id),
            'includeRows'           => $includeRows,
            'includeReadyCount'     => $includeReadyCount,
            'includeTotalCount'     => count($includeRows),
            'includeThreshold'      => $includeThreshold,
            'entryAgeThreshold'     => $entryAgeThreshold,
            // Global (all-sites) counts for the system-wide action buttons.
            'globalStaleCount'        => $globalStaleCount,
            'globalIncludeReadyCount' => $globalIncludeReadyCount,
            'globalPendingCount'      => $plugin->visits->getPendingCount(),
        ], View::TEMPLATE_MODE_CP);
    }

    /**
     * Resolve the site to scope the diagnostics report to, from the CP's
     * native site switcher — a ?site={handle} query param — falling back to
     * the current site, same as Blitz's own DiagnosticsUtility.
     */
    private static function requestedSite(): Site
    {
        $siteHandle = Craft::$app->getRequest()->getParam('site');
        $site = is_string($siteHandle) ? Craft::$app->getSites()->getSiteByHandle($siteHandle) : null;

        return $site ?? Craft::$app->getSites()->getCurrentSite();
    }

    private static function formatAge(int $seconds): string
    {
        if ($seconds < 180)    return $seconds . 's';                      // < 3 min  → seconds
        if ($seconds < 10800)  return round($seconds / 60) . 'm';          // < 3 hr   → minutes
        if ($seconds < 259200) return round($seconds / 3600, 1) . 'h';    // < 3 days → hours
        return round($seconds / 86400, 1) . 'd';                           // ≥ 3 days → days
    }
}
