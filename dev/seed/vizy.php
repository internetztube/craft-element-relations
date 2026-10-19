<?php
/**
 * Vizy: field "vizy" on Pages with one block type "Teaser" holding the Entries field `relatedEntries`.
 * "Page via Vizy link": paragraph with a link mark to Page C plus an image node of the test asset.
 * "Page via Vizy block": one Teaser block whose `relatedEntries` points to Page C.
 * "Page via Vizy (no refs)": plain text and an external link only, must produce no relation.
 */

use craft\fieldlayoutelements\CustomField;
use craft\helpers\Json;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use verbb\vizy\fields\VizyField;
use verbb\vizy\models\BlockType;

return function (array $ctx): void {
    if (!Craft::$app->getFields()->getFieldByHandle('vizy')) {
        $layout = new FieldLayout(['type' => BlockType::class]);
        $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
        $tab->setElements([new CustomField(Craft::$app->getFields()->getFieldByHandle('relatedEntries'))]);
        $layout->setTabs([$tab]);
        ensureField(VizyField::class, ['name' => 'Vizy', 'handle' => 'vizy', 'fieldData' => [[
            'id' => 'group-content',
            'name' => 'Content',
            'blockTypes' => [[
                'id' => 'type-teaser',
                'name' => 'Teaser',
                'handle' => 'teaser',
                'icon' => ['value' => 'newspaper'],
                'enabled' => true,
                'layoutUid' => \craft\helpers\StringHelper::UUID(),
                'layout' => Json::encode($layout->getConfig()),
            ]],
        ]]]);
    }
    addFieldToLayout(Craft::$app->getFields()->getFieldByHandle('vizy'), $ctx['entryType']);

    $target = $ctx['target'];
    $asset = $ctx['asset'];
    $text = fn(string $text, array $marks = []) => ['type' => 'text', 'text' => $text] + ($marks ? ['marks' => $marks] : []);

    ensureEntry('Page via Vizy link', ['vizy' => [
        ['type' => 'paragraph', 'content' => [
            $text('See '),
            $text('Page C', [['type' => 'link', 'attrs' => ['href' => "{$target->url}#entry:{$target->id}@{$target->siteId}"]]]),
        ]],
        ['type' => 'image', 'attrs' => ['id' => $asset->id, 'src' => "{$asset->url}#asset:{$asset->id}:url"]],
    ]]);
    ensureEntry('Page via Vizy block', ['vizy' => [
        ['type' => 'vizyBlock', 'attrs' => [
            'id' => 'vizy-block-teaser',
            'enabled' => true,
            'values' => ['type' => 'type-teaser', 'content' => ['fields' => ['relatedEntries' => [$target->id]]]],
        ]],
    ]]);
    ensureEntry('Page via Vizy (no refs)', ['vizy' => [
        ['type' => 'paragraph', 'content' => [
            $text('Nothing #entry here, '),
            $text('external', [['type' => 'link', 'attrs' => ['href' => 'https://example.com/#top']]]),
        ]],
    ]]);
};
