<?php
/**
 * Matrix (Craft 5 nested entries): entry type "Teaser" holding the Entries field, Matrix field "teasers" on Pages,
 * "Page via Matrix" with one teaser block relating to Page C. The relation must be attributed to the owner entry.
 */

use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Matrix;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

return function (array $ctx): void {
    $entries = Craft::$app->getEntries();
    if (!$teaserType = $entries->getEntryTypeByHandle('teaser')) {
        $layout = new FieldLayout(['type' => Entry::class]);
        $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
        $tab->setElements([new CustomField(Craft::$app->getFields()->getFieldByHandle('relatedEntries'))]);
        $layout->setTabs([$tab]);
        $teaserType = new EntryType(['name' => 'Teaser', 'handle' => 'teaser']);
        $teaserType->setFieldLayout($layout);
        $entries->saveEntryType($teaserType) or fail($teaserType);
    }

    $field = ensureField(Matrix::class, ['name' => 'Teasers', 'handle' => 'teasers', 'entryTypes' => [$teaserType]]);
    addFieldToLayout($field, $ctx['entryType']);

    ensureEntry('Page via Matrix', ['teasers' => [
        'new1' => ['type' => 'teaser', 'enabled' => true, 'fields' => ['relatedEntries' => [$ctx['target']->id]]],
    ]]);
};
