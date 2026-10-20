<?php

use craft\elements\Entry;
use internetztube\elementRelations\elements\Usage;
use internetztube\elementRelations\models\RelationsModel;

return function (array $ctx): void {
    checkRelation($ctx['target'], 'Page via Matrix', 'Matrix nested entry');
    $titles = relatingTitles($ctx['target']);
    check(count(array_keys($titles, 'Page via Matrix', true)) === 1, 'owner is listed exactly once');

    $owner = Entry::find()->title('Page via Matrix')->one();
    $nested = Entry::find()->type('teaser')->ownerId($owner->id)->status(null)->one();
    $sourceIds = (new RelationsModel($ctx['target']->id, $ctx['target']->siteId))->getSourceElementIds([]);
    check($nested !== null && !in_array($nested->id, $sourceIds, true), 'nested entry itself is not a source (only the owner)');

    replaceRoundTrip(\craft\elements\Entry::find()->title('Page via Matrix')->one(), $ctx['target'], 'Entries field inside a Matrix block', fn($e, $t) => array_map('intval', $e->getFieldValue('teasers')->one()->getFieldValue('relatedEntries')->ids()) === [$t->id]);

    // a disabled block between two enabled ones: still found, said so in the Field column, replaced and rolled back
    $elements = Craft::$app->getElements();
    foreach (Entry::find()->title('Page via Matrix (disabled block)')->status(null)->all() as $old) { // an aborted run
        $elements->deleteElement($old, true);
    }
    $page = new Entry(['sectionId' => $owner->sectionId, 'typeId' => $owner->typeId, 'title' => 'Page via Matrix (disabled block)']);
    $page->setFieldValue('teasers', [
        'new1' => ['type' => 'teaser', 'enabled' => true, 'fields' => []],
        'new2' => ['type' => 'teaser', 'enabled' => false, 'fields' => ['relatedEntries' => [$ctx['target']->id]]],
        'new3' => ['type' => 'teaser', 'enabled' => true, 'fields' => []],
    ]);
    $elements->saveElement($page) or throw new RuntimeException(json_encode($page->getErrors()));
    flushQueue();
    checkRelation($ctx['target'], 'Page via Matrix (disabled block)', 'Matrix, disabled block in between');
    $fieldColumn = function () use ($ctx): string {
        $query = Craft::configure(Usage::find()->status(null), ['usesElement' => $ctx['target']->id]);
        foreach ($query->all() as $usage) {
            if ((string)$usage === 'Page via Matrix (disabled block)') {
                return strip_tags($usage->getAttributeHtml(Usage::ATTR_FIELD));
            }
        }
        return '';
    };
    check(str_contains($fieldColumn(), 'in a disabled block'), 'the Field column says the usage is in a disabled block (' . $fieldColumn() . ')');
    $middle = fn($e) => $e->getFieldValue('teasers')->status(null)->all()[1] ?? null;
    replaceRoundTrip($page, $ctx['target'], 'Matrix, disabled block in between', fn($e, $t) => !$middle($e)?->enabled && array_map('intval', $middle($e)?->getFieldValue('relatedEntries')->ids() ?? []) === [$t->id]);
    $elements->deleteElement($page, true);
};
