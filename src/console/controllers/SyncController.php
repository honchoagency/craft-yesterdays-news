<?php

namespace honchoagency\yesterdaysnews\console\controllers;

use Craft;
use craft\console\Controller;
use honchoagency\yesterdaysnews\jobs\SyncJob;
use honchoagency\yesterdaysnews\YesterdaysNews;
use yii\console\ExitCode;

/**
 * Sync controller — copy untracked Blitz URIs into the YN visits table.
 *
 * Bots don't execute JS, so Blitz may cache pages that the YN beacon never
 * fires for. Running sync populates those missing rows using dateCached as
 * lastVisitedAt, so the normal prune cycle can expire them like any stale page.
 *
 * Usage:
 *   php craft yesterdays-news/sync/run           # dispatches SyncJob to queue (default)
 *   php craft yesterdays-news/sync/run --queue=0 # runs synchronously
 */
class SyncController extends Controller
{
    public $defaultAction = 'run';

    /**
     * Whether to dispatch sync as a queue job (true) or run synchronously (false).
     * Default: true — consistent with the site's runQueueAutomatically = false setup.
     */
    public bool $queue = true;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['queue']);
    }

    /**
     * Sync untracked Blitz URIs into the YN visits table.
     */
    public function actionRun(): int
    {
        if ($this->queue) {
            Craft::$app->getQueue()->push(new SyncJob());
            $this->stdout("Yesterday's News: SyncJob dispatched to queue.\n");
        } else {
            $this->stdout("Yesterday's News: running sync synchronously...\n\n");
            $count = YesterdaysNews::getInstance()->visits->syncFromBlitz(
                fn(string $line) => $this->stdout('  ' . $line)
            );
            $this->stdout("\nDone. $count URI(s) inserted.\n");
        }

        return ExitCode::OK;
    }
}
