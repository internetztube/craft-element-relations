<?php
/** Core Assets field on the Pages entry type (used by dev/e2e.php for the Pro "Unused" source). */
return function (array $ctx): void {
    $field = ensureField(\craft\fields\Assets::class, ['name' => 'Related Assets', 'handle' => 'relatedAssets']);
    addFieldToLayout($field, $ctx['entryType']);
};
