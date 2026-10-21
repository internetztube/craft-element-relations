<?php
/**
 * Element Relations config.php
 *
 * Copy this file to `config/element-relations.php` in your Craft project and adjust it. Values set here override the
 * plugin settings in the control panel. Like every Craft config file it supports multi-environment arrays
 * (`'*' => [...], 'production' => [...]`).
 */

return [
    // Maximum number of elements handled per queue job when the whole relations cache is rebuilt
    // (plugin install, Utilities → Element Relations, `php craft element-relations/resave-relations`).
    'bulkRefreshBatchSize' => 2000,

    // Pro: replacements touching more elements than this run as a queue job instead of in the request.
    'replaceSyncLimit' => 25,

    // Feature flags.
    'features' => [
        // Offer the Lite/Pro editions. Off: the plugin is a single Lite edition.
        'editions' => false,
    ],
];
