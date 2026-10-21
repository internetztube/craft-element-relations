<?php
/**
 * FreeLink: multi-link field "Page via FreeLink" -> Page C + test asset; single-link field "Page via FreeLink (single)"
 * -> Page C, and "Page via FreeLink (url)" with only a URL link (must relate to nothing).
 */

use justinholtweb\freelink\fields\FreeLinkField;

return function (array $ctx): void {
    $linkTypes = ['url' => ['enabled' => true], 'entry' => ['enabled' => true], 'asset' => ['enabled' => true]];
    $multi = ensureField(FreeLinkField::class, ['name' => 'FreeLink', 'handle' => 'freeLink', 'linkTypes' => $linkTypes, 'multipleLinks' => true]);
    $single = ensureField(FreeLinkField::class, ['name' => 'FreeLink Single', 'handle' => 'freeLinkSingle', 'linkTypes' => $linkTypes]);
    addFieldToLayout($multi, $ctx['entryType']);
    addFieldToLayout($single, $ctx['entryType']);
    ensureEntry('Page via FreeLink', ['freeLink' => [
        ['type' => 'entry', 'targetId' => $ctx['target']->id],
        ['type' => 'asset', 'targetId' => $ctx['asset']->id],
    ]]);
    ensureEntry('Page via FreeLink (single)', ['freeLinkSingle' => ['type' => 'entry', 'targetId' => $ctx['target']->id]]);
    ensureEntry('Page via FreeLink (url)', ['freeLinkSingle' => ['type' => 'url', 'value' => 'https://example.com']]);
};
