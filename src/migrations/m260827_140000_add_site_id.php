<?php

namespace honchoagency\yesterdaysnews\migrations;

use Craft;
use craft\db\Migration;
use craft\helpers\MigrationHelper;

/**
 * Adds a siteId column to yesterdays_news_visits.
 *
 * The previous unique index on `url` alone assumed every path belonged to
 * exactly one site. On multi-site installs the same path is a different page
 * per site, so visits collapsed into one row and pruning resolved every
 * stored path against the primary site's base URL — silently wrong for any
 * other site. Existing rows are backfilled to the primary site, matching the
 * primary-site resolution the plugin already assumed everywhere before this.
 */
class m260827_140000_add_site_id extends Migration
{
    public function safeUp(): bool
    {
        $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

        $this->addColumn(
            '{{%yesterdays_news_visits}}',
            'siteId',
            $this->integer()->notNull()->defaultValue($primarySiteId)->after('id'),
        );

        $this->addForeignKey(
            null,
            '{{%yesterdays_news_visits}}',
            ['siteId'],
            '{{%sites}}',
            ['id'],
            'CASCADE',
            null,
        );

        // Replace the single-column unique index — the same path can now
        // exist once per site rather than once per install.
        MigrationHelper::dropIndexIfExists('{{%yesterdays_news_visits}}', 'url', true, $this);
        $this->createIndex(null, '{{%yesterdays_news_visits}}', ['siteId', 'url'], true);

        return true;
    }

    public function safeDown(): bool
    {
        MigrationHelper::dropIndexIfExists('{{%yesterdays_news_visits}}', ['siteId', 'url'], true, $this);
        MigrationHelper::dropForeignKeyIfExists('{{%yesterdays_news_visits}}', ['siteId'], $this);
        $this->dropColumn('{{%yesterdays_news_visits}}', 'siteId');
        $this->createIndex(null, '{{%yesterdays_news_visits}}', 'url', true);

        return true;
    }
}
