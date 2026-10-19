<?php
/** Typed Link Field (sebastianlenz/linkfield): "Page via Typed Link" -> Page C. */

use lenz\linkfield\fields\LinkField;

return function (array $ctx): void {
    $field = ensureField(LinkField::class, ['name' => 'Typed Link', 'handle' => 'typedLink']);
    addFieldToLayout($field, $ctx['entryType']);
    ensureEntry('Page via Typed Link', ['typedLink' => [
        'type' => 'entry',
        'linkedId' => $ctx['target']->id,
        'linkedSiteId' => $ctx['siteId'],
    ]]);
};
