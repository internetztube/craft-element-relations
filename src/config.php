<?php

declare(strict_types=1);

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

    // Pro: elements that live content uses can't be deleted, disabled, expired or scheduled in the control panel
    // without the "Delete or disable elements that are in use" permission. false: Craft's usual behaviour.
    'protectInUse' => true,

    // Feature flags.
    'features' => [
        // Offer the Lite/Pro editions. Off: the plugin is a single Lite edition.
        'editions' => false,
    ],
];
