<?php

namespace honchoagency\yesterdaysnews\migrations;

use craft\db\Migration;

/**
 * Adds the visitCount column to yesterdays_news_visits.
 *
 * Pages with fewer beacon pings than the configured lowVisitCount threshold
 * are pruned on a shorter schedule, reducing the cache footprint of bot traffic.
 * Existing rows default to 0 — they will be treated as low-visit until the
 * beacon accumulates enough pings to cross the configured boundary.
 */
class m260602_000000_add_visit_count extends Migration
{
    public function safeUp(): bool
    {
        $this->addColumn(
            '{{%yesterdays_news_visits}}',
            'visitCount',
            $this->integer()->unsigned()->notNull()->defaultValue(0)->after('lastVisitedAt'),
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn('{{%yesterdays_news_visits}}', 'visitCount');

        return true;
    }
}
