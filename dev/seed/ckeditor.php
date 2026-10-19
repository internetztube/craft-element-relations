<?php
/** CKEditor (craftcms/ckeditor): a field whose content links to Page C and embeds the test asset via reference tags. */
return function (array $ctx): void {
    $field = ensureField(\craft\ckeditor\Field::class, ['name' => 'CKEditor', 'handle' => 'ckeditor']);
    addFieldToLayout($field, $ctx['entryType']);
    $target = $ctx['target'];
    $asset = $ctx['asset'];
    // The fallback URL after `||` must be non-empty, otherwise HtmlField does not recognise the ref tag.
    ensureEntry('Page via CKEditor', ['ckeditor' => sprintf(
        '<p><a href="{entry:%d@%d:url||%s}">Page C</a></p><p><img src="{asset:%d:url||%s}" alt="test"></p>',
        $target->id, $target->siteId, $target->getUrl(), $asset->id, $asset->getUrl() ?: 'http://localhost/test.png',
    )]);
};
