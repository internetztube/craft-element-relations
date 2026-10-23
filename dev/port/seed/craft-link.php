<?php
/** Craft 5 core Link field: "Page via Craft Link" -> Page C, "Page via Craft Link (asset)" -> test asset. */

use craft\fields\Link;

return function (array $ctx): void {
    $field = ensureField(Link::class, ['name' => 'Craft Link', 'handle' => 'craftLink', 'types' => ['url', 'entry', 'asset']]);
    addFieldToLayout($field, $ctx['entryType']);
    ensureEntry('Page via Craft Link', ['craftLink' => ['type' => 'entry', 'value' => $ctx['target']->id]]);
    ensureEntry('Page via Craft Link (asset)', ['craftLink' => ['type' => 'asset', 'value' => $ctx['asset']->id]]);
};
