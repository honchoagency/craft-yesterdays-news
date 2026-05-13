<?php

namespace honchoagency\yesterdaysnews\migrations;

use craft\db\Migration;

/**
 * Install migration for Yesterday's News plugin.
 * Creates the yesterdays_news_visits table.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%yesterdays_news_visits}}', [
            'id'            => $this->primaryKey(),
            'url'           => $this->string(500)->notNull(),
            'lastVisitedAt' => $this->dateTime()->notNull(),
            'dateCreated'   => $this->dateTime()->notNull(),
            'dateUpdated'   => $this->dateTime()->notNull(),
            'uid'           => $this->uid(),
        ]);

        // Unique index on url — required for upsert ON DUPLICATE KEY UPDATE.
        // string(500) keeps the index within MySQL's 767-byte utf8mb4 key limit.
        $this->createIndex(null, '{{%yesterdays_news_visits}}', 'url', true);

        // Non-unique index on lastVisitedAt for efficient range queries during pruning.
        $this->createIndex(null, '{{%yesterdays_news_visits}}', 'lastVisitedAt');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%yesterdays_news_visits}}');

        return true;
    }
}
