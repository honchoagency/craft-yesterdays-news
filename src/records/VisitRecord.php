<?php

namespace honchoagency\yesterdaysnews\records;

use craft\db\ActiveRecord;

/**
 * Visit record for the yesterdays_news_visits table.
 *
 * @property int    $id
 * @property string $url
 * @property string $lastVisitedAt
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class VisitRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%yesterdays_news_visits}}';
    }
}
