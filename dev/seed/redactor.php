<?php
/** Redactor (craftcms/redactor): a field whose content links to Page C and embeds the test asset via reference tags. */
return function (array $ctx): void {
    $field = ensureField(\craft\redactor\Field::class, ['name' => 'Redactor', 'handle' => 'redactor']);
    addFieldToLayout($field, $ctx['entryType']);
    $target = $ctx['target'];
    $asset = $ctx['asset'];
    // Same format as CKEditor: the fallback URL after `||` must be non-empty, otherwise HtmlField drops the ref tag.
    ensureEntry('Page via Redactor', ['redactor' => sprintf(
        '<p><a href="{entry:%d@%d:url||%s}">Page C</a></p><p><img src="{asset:%d:url||%s}" alt="test"></p>',
        $target->id, $target->siteId, $target->getUrl(), $asset->id, $asset->getUrl() ?: 'http://localhost/test.png',
    )]);
};
