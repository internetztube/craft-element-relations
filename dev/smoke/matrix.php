<?php

declare(strict_types=1);

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Elements;
use Internetztube\ElementRelations\Elements\Usage;
use Internetztube\ElementRelations\Models\RelationsModel;

return function (array $ctx): void {
    $target = $ctx['target'];
    checkRelation($target, 'Page via Matrix', 'Matrix nested entry');
    $titles = relatingTitles($target);
    check(count(array_keys($titles, 'Page via Matrix', true)) === 1, 'owner is listed exactly once');

    $owner = Entry::find()->title('Page via Matrix')->one() ?? throw new RuntimeException('Page via Matrix');
    $nested = Entry::find()->type('teaser')->ownerId($owner->id)->status(null)->one();
    $sourceIds = (new RelationsModel((int)$target->id, (int)$target->siteId))->getSourceElementIds([]);
    check($nested !== null && !in_array($nested->id, $sourceIds, true), 'nested entry itself is not a source (only the owner)');

    replaceRoundTrip($owner, $target, 'Entries field inside a Matrix block', fn($e, $t) => array_map('intval', $e->getFieldValue('teasers')->one()->getFieldValue('relatedEntries')->ids()) === [(int)$t->id]);

    // a disabled block between two enabled ones: still found, said so in the Field column, replaced and rolled back
    foreach (Entry::find()->title('Page via Matrix (disabled block)')->status(null)->all() as $old) { // an aborted run
        Elements::deleteElement($old, true);
    }
    $page = new Entry(['sectionId' => $owner->sectionId, 'typeId' => $owner->typeId, 'title' => 'Page via Matrix (disabled block)']);
    $page->setFieldValue('teasers', [
        'new1' => ['type' => 'teaser', 'enabled' => true, 'fields' => []],
        'new2' => ['type' => 'teaser', 'enabled' => false, 'fields' => ['relatedEntries' => [$target->id]]],
        'new3' => ['type' => 'teaser', 'enabled' => true, 'fields' => []],
    ]);
    Elements::saveElement($page) or fail($page);
    flushQueue();
    checkRelation($target, 'Page via Matrix (disabled block)', 'Matrix, disabled block in between');
    $fieldColumn = function () use ($target): string {
        foreach (Usage::find()->usesElement($target->id)->status(null)->all() as $usage) {
            if ((string)$usage === 'Page via Matrix (disabled block)') {
                return strip_tags((string)$usage->getAttributeHtml(Usage::ATTR_FIELD));
            }
        }
        return '';
    };
    check(str_contains($fieldColumn(), 'in a disabled block'), 'the Field column says the usage is in a disabled block (' . $fieldColumn() . ')');
    $middle = fn($e) => $e->getFieldValue('teasers')->status(null)->all()[1] ?? null;
    replaceRoundTrip($page, $target, 'Matrix, disabled block in between', fn($e, $t) => !$middle($e)?->enabled && array_map('intval', $middle($e)?->getFieldValue('relatedEntries')->ids() ?? []) === [(int)$t->id]);
    Elements::deleteElement($page, true);
};
