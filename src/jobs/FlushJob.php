<?php

namespace honchoagency\yesterdaysnews\jobs;

use craft\queue\BaseJob;
use honchoagency\yesterdaysnews\YesterdaysNews;

/**
 * Flush visit buffer from Craft cache (Redis) into the DB visits table.
 *
 * Should be dispatched every 5 minutes via cron:
 *   php craft yesterdays-news/flush/run
 */
class FlushJob extends BaseJob
{
    public function execute($queue): void
    {
        $this->setProgress($queue, 0.0, 'Flushing visit buffer to DB');

        YesterdaysNews::getInstance()->visits->flushBufferToDb();

        $this->setProgress($queue, 1.0, 'Flush complete');
    }

    protected function defaultDescription(): ?string
    {
        return "Yesterday's News: flush visit buffer";
    }
}
