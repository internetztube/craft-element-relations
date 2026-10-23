<?php

declare(strict_types=1);

/**
 * Matrix (nested entries): entry type "Teaser" holding the Entries field, Matrix field "teasers" on Pages,
 * "Page via Matrix" with one teaser block relating to Page C. The relation must be attributed to the owner entry.
 */

use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Matrix;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;

return function (array $ctx): void {
    if (!$teaserType = EntryTypes::getEntryTypeByHandle('teaser')) {
        $teaserType = new EntryType(['name' => 'Teaser', 'handle' => 'teaser', 'hasTitleField' => false]);
        $teaserType->setFieldLayout(layoutFor(Entry::class, [Fields::getFieldByHandle('relatedEntries') ?? throw new RuntimeException('relatedEntries')]));
        EntryTypes::saveEntryType($teaserType) or fail($teaserType);
        $teaserType = EntryTypes::getEntryTypeByHandle('teaser') ?? throw new RuntimeException('teaser');
    }

    $field = ensureField(Matrix::class, 'teasers', 'Teasers', ['entryTypes' => [$teaserType]]);
    addFieldToLayout($field, $ctx['entryType']);

    ensureEntry('Page via Matrix', ['teasers' => [
        'new1' => ['type' => 'teaser', 'enabled' => true, 'fields' => ['relatedEntries' => [$ctx['target']->id]]],
    ]]);
};
