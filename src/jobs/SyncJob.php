<?php

namespace honchoagency\yesterdaysnews\jobs;

use craft\queue\BaseJob;
use honchoagency\yesterdaysnews\YesterdaysNews;

/**
 * Sync Blitz-cached URLs that have no YN visit record into the visits table.
 *
 * Should be dispatched periodically via cron (e.g. every hour, before PruneJob):
 *   php craft yesterdays-news/sync/run
 */
class SyncJob extends BaseJob
{
    public function execute($queue): void
    {
        $this->setProgress($queue, 0.0, 'Syncing untracked Blitz URIs to visits table');

        $count = YesterdaysNews::getInstance()->visits->syncFromBlitz();

        $this->setProgress($queue, 1.0, "Sync complete: $count URI(s) inserted");
    }

    protected function defaultDescription(): ?string
    {
        return "Yesterday's News: sync from Blitz";
    }
}
