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
            'siteId'        => $this->integer()->notNull(),
            'url'           => $this->string(500)->notNull(),
            'lastVisitedAt' => $this->dateTime()->notNull(),
            'visitCount'    => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'dateCreated'   => $this->dateTime()->notNull(),
            'dateUpdated'   => $this->dateTime()->notNull(),
            'uid'           => $this->uid(),
        ]);

        $this->addForeignKey(
            null,
            '{{%yesterdays_news_visits}}',
            ['siteId'],
            '{{%sites}}',
            ['id'],
            'CASCADE',
            null,
        );

        // Unique index on [siteId, url] — the same path can exist once per site.
        // string(500) keeps the index within MySQL's 767-byte utf8mb4 key limit.
        $this->createIndex(null, '{{%yesterdays_news_visits}}', ['siteId', 'url'], true);

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
