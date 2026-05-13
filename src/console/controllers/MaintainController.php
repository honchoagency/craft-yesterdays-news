<?php

namespace honchoagency\yesterdaysnews\console\controllers;

use Craft;
use craft\console\Controller;
use honchoagency\yesterdaysnews\jobs\FlushJob;
use honchoagency\yesterdaysnews\jobs\SyncJob;
use yii\console\ExitCode;

/**
 * Maintain controller — dispatch both FlushJob and SyncJob in one cron entry.
 *
 * Usage:
 *   php craft yesterdays-news/maintain/run
 *
 * Suggested cron (every 5 minutes):
 *   *\/5 * * * * php craft yesterdays-news/maintain/run
 *   *\/5 * * * * php craft queue/run
 */
class MaintainController extends Controller
{
    public $defaultAction = 'run';

    /**
     * Dispatch FlushJob and SyncJob to the queue.
     */
    public function actionRun(): int
    {
        $queue = Craft::$app->getQueue();

        $queue->push(new FlushJob());
        $this->stdout("Yesterday's News: FlushJob dispatched to queue.\n");

        $queue->push(new SyncJob());
        $this->stdout("Yesterday's News: SyncJob dispatched to queue.\n");

        return ExitCode::OK;
    }
}
