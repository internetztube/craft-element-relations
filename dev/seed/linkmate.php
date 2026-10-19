<?php
/** LinkMate: "Page via LinkMate" -> Page C, "Page via LinkMate (url)" with a URL link (must relate to nothing). */

use vaersaagod\linkmate\fields\LinkField;

return function (array $ctx): void {
    $field = ensureField(LinkField::class, ['name' => 'LinkMate', 'handle' => 'linkMate', 'allowedLinkNames' => '*']);
    addFieldToLayout($field, $ctx['entryType']);
    ensureEntry('Page via LinkMate', ['linkMate' => ['type' => 'entry', 'value' => $ctx['target']->id]]);
    ensureEntry('Page via LinkMate (url)', ['linkMate' => ['type' => 'url', 'value' => 'https://example.com']]);
};
