<?php

namespace honchoagency\yesterdaysnews\console\controllers;

use Craft;
use craft\console\Controller;
use honchoagency\yesterdaysnews\jobs\PruneJob;
use honchoagency\yesterdaysnews\YesterdaysNews;
use yii\console\ExitCode;

/**
 * Prune controller — dispatch or run stale-URL pruning from the CLI.
 *
 * Usage:
 *   php craft yesterdays-news/prune/run           # dispatches PruneJob to queue (default)
 *   php craft yesterdays-news/prune/run --queue=0 # runs synchronously
 */
class PruneController extends Controller
{
    public $defaultAction = 'run';

    /**
     * Whether to dispatch pruning as a queue job (true) or run synchronously (false).
     * Default: true — consistent with the site's runQueueAutomatically = false setup.
     */
    public bool $queue = true;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['queue']);
    }

    /**
     * Prune stale URLs from the Blitz cache.
     */
    public function actionRun(): int
    {
        if ($this->queue) {
            Craft::$app->getQueue()->push(new PruneJob());
            $this->stdout("Yesterday's News: PruneJob dispatched to queue.\n");
        } else {
            $this->stdout("Yesterday's News: running prune synchronously...\n\n");
            YesterdaysNews::getInstance()->visits->pruneStaleUrls(
                fn(string $line) => $this->stdout('  ' . $line)
            );
            $this->stdout("\nDone.\n");
        }

        return ExitCode::OK;
    }
}
