<?php

namespace honchoagency\yesterdaysnews\models;

use craft\base\Model;

/**
 * Yesterday's News settings
 *
 * Override via config/yesterdays-news.php:
 *
 * return [
 *     '*' => [
 *         'threshold'        => 86400,
 *         'entryAgeThreshold' => 2592000,
 *         'includeThreshold' => 86400,
 *         'includeTemplates' => [
 *             '_includes/_article-card'          => 'entryId',
 *             'sections/_featured'               => 'featuredId',
 *             'sections/_partials/featured-live' => 'featuredId',
 *         ],
 *     ],
 * ];
 */
class Settings extends Model
{
    /**
     * Age threshold in seconds after which a page URL is considered stale and
     * pruned from the Blitz cache. Default: 86400 (24 hours). Set to 0 to disable.
     */
    public int $threshold = 86400;

    /**
     * How long (seconds) since an entry's last dateUpdated before its cached
     * includes become eligible for pruning. Default: 2592000 (30 days).
     */
    public int $entryAgeThreshold = 2592000;

    /**
     * How long (seconds) a cached include record must be before it is eligible
     * for pruning (even if the entry is old). Default: 86400 (24 hours).
     * Set to 0 to disable template-based include pruning.
     */
    public int $includeThreshold = 86400;

    /**
     * Map of Blitz include template path → the JSON params key that holds the
     * related entry's ID. An include is pruned when BOTH conditions are met:
     *   - The related entry has not been updated in more than $entryAgeThreshold seconds.
     *   - The cached include record is older than $includeThreshold seconds.
     *
     * @var array<string, string>
     */
    public array $includeTemplates = [
        '_includes/_article-card'          => 'entryId',
        'sections/_featured'               => 'featuredId',
        'sections/_partials/featured-live' => 'featuredId',
    ];

    public function defineRules(): array
    {
        return [
            [['threshold', 'entryAgeThreshold', 'includeThreshold'], 'integer', 'min' => 0],
            [['includeTemplates'], 'safe'],
        ];
    }
}
