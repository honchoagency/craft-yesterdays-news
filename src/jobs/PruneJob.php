<?php

namespace honchoagency\yesterdaysnews\jobs;

use craft\queue\BaseJob;
use honchoagency\yesterdaysnews\YesterdaysNews;

/**
 * Prune stale URLs from the Blitz cache and the visits DB table.
 *
 * Should be dispatched periodically via cron (e.g. every hour):
 *   php craft yesterdays-news/prune/run
 */
class PruneJob extends BaseJob
{
    public function execute($queue): void
    {
        $this->setProgress($queue, 0.0, 'Pruning stale Blitz cache URLs');

        YesterdaysNews::getInstance()->visits->pruneStaleUrls();

        $this->setProgress($queue, 1.0, 'Prune complete');
    }

    protected function defaultDescription(): ?string
    {
        return "Yesterday's News: prune stale cache URLs";
    }
}
