<?php

/**
 * Yesterday's News config.php
 *
 * This file exists only as a template for the Yesterday's News settings.
 * It does nothing on its own.
 *
 * Don't edit this file, instead copy it to 'craft/config' as 'yesterdays-news.php'
 * and make your changes there to override default settings.
 *
 * Once copied to 'craft/config', this file will be multi-environment aware as
 * well, so you can have different settings groups for each environment, just as
 * you do for 'general.php'
 */

return [
    '*' => [

        // Whether the JS beacon should be injected into frontend pages.
        // Set to false to stop tracking visits without uninstalling the plugin.
        'beaconEnabled' => true,

        // Whether stale page URLs should be pruned from the Blitz cache.
        'pagePruningEnabled' => true,

        // Pages with fewer visits than this number use the shorter
        // `lowVisitThreshold` below. Bot traffic typically produces 0 or 1
        // beacon pings (bots don't execute JavaScript), so a value of 2 means
        // only pages with at least 2 real-user visits use the full threshold.
        'lowVisitCount' => 2,

        // How long to keep low-visit pages in the Blitz cache. Should be much
        // shorter than `threshold` — these pages are likely bot-only traffic.
        'lowVisitThreshold' => 1800, // 30 minutes

        // The number of seconds since a page was last visited before it is
        // considered stale and pruned from the Blitz cache.
        'threshold' => 86400, // 24 hours

        // Whether stale cached includes should be pruned from the Blitz cache.
        'includePruningEnabled' => true,

        // The number of seconds since an entry's last dateUpdated before its cached
        // includes become eligible for pruning. Both this threshold and
        // `includeThreshold` must be exceeded before an include is pruned.
        'entryAgeThreshold' => 2592000, // 30 days

        // The number of seconds old a cached include must be before it is eligible for
        // pruning. Both this threshold and `entryAgeThreshold` must be exceeded
        // before an include is pruned.
        'includeThreshold' => 86400, // 24 hours

        // A map of Blitz include template paths to the params key that holds the
        // related entry's ID. When Blitz caches a template include it stores the
        // params passed to it, so if your template is called like:
        //
        //   {% include '_components/article-card' with { entryId: entry.id } %}
        //
        // ...then the entry for that include would be:
        //
        //   '_components/article-card' => 'entryId'
        //
        // Yesterday's News uses this to find the associated entry and check whether
        // it is old enough to prune.
        //'includeTemplates' => [
        //    '_components/article-card' => 'entryId',
        //],
    ],
];
