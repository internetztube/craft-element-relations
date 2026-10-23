<?php

declare(strict_types=1);

/**
 * Craft's core Markdown field: content that links to Page C and embeds the test asset through reference tags (it
 * tracks references, the plugin reads the tags from its text).
 */

use CraftCms\Cms\Field\Markdown;

return function (array $ctx): void {
    $field = ensureField(Markdown::class, 'markdown', 'Markdown');
    addFieldToLayout($field, $ctx['entryType']);
    $target = $ctx['target'];
    $asset = $ctx['asset'];
    ensureEntry('Page via Markdown', ['markdown' => sprintf(
        // ordinary text with `||` and braces must not be taken for reference tags
        "fast || cheap {not a tag}\n\n[Page C]({entry:%d@%d:url||%s})\n\n![test]({asset:%d:url||%s})\n",
        $target->id, $target->siteId, $target->getUrl(), $asset->id, $asset->getUrl() ?: 'http://localhost/test.png',
    )]);
};
