<?php

namespace honchoagency\yesterdaysnews\console\controllers;

use Craft;
use craft\console\Controller;
use honchoagency\yesterdaysnews\jobs\FlushJob;
use honchoagency\yesterdaysnews\YesterdaysNews;
use yii\console\ExitCode;

/**
 * Flush controller — dispatch or run the visit buffer flush from the CLI.
 *
 * Usage:
 *   php craft yesterdays-news/flush/run           # dispatches FlushJob to queue (default)
 *   php craft yesterdays-news/flush/run --queue=0 # runs synchronously
 */
class FlushController extends Controller
{
    public $defaultAction = 'run';

    /**
     * Whether to dispatch the flush as a queue job (true) or run synchronously (false).
     */
    public bool $queue = true;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['queue']);
    }

    /**
     * Flush buffered visits from the Craft cache into the DB.
     */
    public function actionRun(): int
    {
        if ($this->queue) {
            Craft::$app->getQueue()->push(new FlushJob());
            $this->stdout("Yesterday's News: FlushJob dispatched to queue.\n");
        } else {
            $this->stdout("Yesterday's News: flushing visit buffer synchronously...\n");
            YesterdaysNews::getInstance()->visits->flushBufferToDb();
            $this->stdout("Yesterday's News: flush complete.\n");
        }

        return ExitCode::OK;
    }
}
