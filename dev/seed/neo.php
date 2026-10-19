<?php
/**
 * Neo (spicyweb/craft-neo): Neo field "neoTeasers" with block type "Teaser" holding the Entries field,
 * "Page via Neo" with one block relating to Page C. The relation must be attributed to the owner entry.
 */

use benf\neo\elements\Block;
use benf\neo\Field as NeoField;
use benf\neo\models\BlockType;
use craft\fieldlayoutelements\CustomField;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

return function (array $ctx): void {
    if (!Craft::$app->getPlugins()->isPluginEnabled('neo')) {
        echo "  (neo not installed, skipped)\n";
        return;
    }

    if (!$field = Craft::$app->getFields()->getFieldByHandle('neoTeasers')) {
        $layout = new FieldLayout(['type' => Block::class]);
        $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
        $tab->setElements([new CustomField(Craft::$app->getFields()->getFieldByHandle('relatedEntries'))]);
        $layout->setTabs([$tab]);
        $blockType = new BlockType(['name' => 'Teaser', 'handle' => 'teaser', 'sortOrder' => 1]);
        $blockType->setFieldLayout($layout);
        $field = ensureField(NeoField::class, ['name' => 'Neo Teasers', 'handle' => 'neoTeasers', 'blockTypes' => [$blockType]]);
        \benf\neo\helpers\Memoize::$blockTypesByFieldId = []; // Neo memoizes the unsaved block types (no ids) during saveField
    }
    addFieldToLayout($field, $ctx['entryType']);

    ensureEntry('Page via Neo', ['neoTeasers' => [
        ['type' => 'teaser', 'enabled' => true, 'level' => 1, 'fields' => ['relatedEntries' => [$ctx['target']->id]]],
    ]]);
};
