<?php

declare(strict_types=1);

/** Core Assets field on the Pages entry type (used by dev/e2e.php for the "Used anywhere" filter). */

use CraftCms\Cms\Field\Assets;

return function (array $ctx): void {
    addFieldToLayout(ensureField(Assets::class, 'relatedAssets', 'Related Assets'), $ctx['entryType']);
};
