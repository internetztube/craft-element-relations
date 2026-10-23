<?php

declare(strict_types=1);

/**
 * Craft's core Link field: "Page via Craft Link" -> Page C, "Page via Craft Link (asset)" -> test asset. Craft 6's Link
 * field no longer writes the `relations` table (it tracks references); the plugin reads it itself.
 */

use CraftCms\Cms\Field\Link;

return function (array $ctx): void {
    $field = ensureField(Link::class, 'craftLink', 'Craft Link', ['types' => ['url', 'entry', 'asset']]);
    addFieldToLayout($field, $ctx['entryType']);
    ensureEntry('Page via Craft Link', ['craftLink' => ['type' => 'entry', 'value' => $ctx['target']->id]]);
    ensureEntry('Page via Craft Link (asset)', ['craftLink' => ['type' => 'asset', 'value' => $ctx['asset']->id]]);
};
