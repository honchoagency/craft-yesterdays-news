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
 *         'pagePruningEnabled'    => true,
 *         'threshold'             => 86400,
 *         'includePruningEnabled' => true,
 *         'entryAgeThreshold'     => 2592000,
 *         'includeThreshold'      => 86400,
 *         'includeTemplates'      => [],
 *     ],
 * ];
 */
class Settings extends Model
{
    /**
     * Whether the JS beacon should be injected into frontend pages.
     * Disable this to stop tracking visits without uninstalling the plugin.
     */
    public bool $beaconEnabled = true;

    /**
     * Whether stale page URLs should be pruned from the Blitz cache.
     */
    public bool $pagePruningEnabled = true;

    /**
     * Age threshold in seconds after which a page URL is considered stale and
     * pruned from the Blitz cache. Default: 86400 (24 hours).
     */
    public int $threshold = 86400;

    /**
     * Whether stale cached includes should be pruned from the Blitz cache.
     */
    public bool $includePruningEnabled = true;

    /**
     * How long (seconds) since an entry's last dateUpdated before its cached
     * includes become eligible for pruning. Default: 2592000 (30 days).
     */
    public int $entryAgeThreshold = 2592000;

    /**
     * How long (seconds) a cached include record must be before it is eligible
     * for pruning (even if the entry is old). Default: 86400 (24 hours).
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
    public array $includeTemplates = [];

    /**
     * Accept both the assoc format from config files (['template' => 'paramKey'])
     * and the row-array format sent by Craft's editable table field
     * ([['template' => '...', 'paramKey' => '...'], ...]).
     *
     * @param array<mixed> $value
     */
    public function setIncludeTemplates(array $value): void
    {
        $first = reset($value);

        if (is_array($first)) {
            $result = [];
            foreach ($value as $row) {
                $template = trim($row['template'] ?? '');
                $paramKey = trim($row['paramKey'] ?? '');
                if ($template !== '' && $paramKey !== '') {
                    $result[$template] = $paramKey;
                }
            }
            $this->includeTemplates = $result;
        } else {
            $this->includeTemplates = $value;
        }
    }

    public function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['beaconEnabled', 'pagePruningEnabled', 'includePruningEnabled'], 'boolean'],
            [['threshold', 'entryAgeThreshold', 'includeThreshold'], 'integer', 'min' => 1],
            [['includeTemplates'], 'safe'],
        ]);
    }
}
