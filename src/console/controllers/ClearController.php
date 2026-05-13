<?php

namespace honchoagency\yesterdaysnews\console\controllers;

use craft\console\Controller;
use honchoagency\yesterdaysnews\YesterdaysNews;
use yii\console\ExitCode;

/**
 * Clear controller — truncate all visit data.
 *
 * Usage:
 *   php craft yesterdays-news/clear/run
 */
class ClearController extends Controller
{
    public $defaultAction = 'run';

    /**
     * Clear all visit records from the DB and flush the in-memory cache buffer.
     */
    public function actionRun(): int
    {
        $count = YesterdaysNews::getInstance()->visits->clearAll();

        $this->stdout(sprintf("Yesterday's News: cleared %d visit record(s).\n", $count));

        return ExitCode::OK;
    }
}
