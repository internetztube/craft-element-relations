<?php

use craft\elements\Entry;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

return function (array $ctx): void {
    $raw = Entry::find()->title('Page via Vizy link')->one()?->getFieldValue('vizy')?->getRawNodes() ?? [];
    check(str_contains(json_encode($raw), "#entry:{$ctx['target']->id}@"), 'stored link mark keeps the #entry:ID@SITE fragment');
    checkRelation($ctx['target'], 'Page via Vizy link', 'Vizy link mark');
    checkRelation($ctx['asset'], 'Page via Vizy link', 'Vizy image node');
    checkRelation($ctx['target'], 'Page via Vizy block', 'Vizy block inner field');

    $empty = Entry::find()->title('Page via Vizy (no refs)')->one();
    check($empty && !ElementRelationsCacheRecord::find()->where(['sourceElementId' => $empty->id])->exists(), 'Vizy entry without references produces no relation');

    // a link to a ref handle no element type has (e.g. a removed plugin's), or to an element that is gone: no relation
    $link = Entry::find()->title('Page via Vizy link')->one();
    $odd = new Entry(['sectionId' => $link->sectionId, 'typeId' => $link->typeId, 'siteId' => $link->siteId]);
    $odd->setFieldValue('vizy', [['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'gone type', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.test/#removedthing:5']]]],
        ['type' => 'text', 'text' => 'gone entry', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.test/#entry:999999999']]]],
        ['type' => 'text', 'text' => 'Page C', 'marks' => [['type' => 'link', 'attrs' => ['href' => "https://example.test/#entry:{$ctx['target']->id}"]]]],
    ]]]);
    $records = \internetztube\elementRelations\services\extractors\FieldExtractorVizyService::getRelations(Craft::$app->getFields()->getFieldByHandle('vizy'), $odd, new ElementRelationsCacheRecord());
    check(array_map(fn($record) => (int)$record->targetElementId, $records ?: []) === [(int)$ctx['target']->id], 'Vizy: a link to an unknown ref type or to a deleted element relates to nothing, the others still count');

    $rawJson = fn($e) => json_encode($e->getFieldValue('vizy')->getRawNodes());
    $link = Entry::find()->title('Page via Vizy link')->one();
    replaceRoundTrip($link, $ctx['target'], 'Vizy link mark', fn($e, $t) => str_contains($rawJson($e), "#entry:{$t->id}@"));
    replaceRoundTrip($link, $ctx['asset'], 'Vizy image node', fn($e, $t) => str_contains($rawJson($e), "#asset:{$t->id}:url") && str_contains($rawJson($e), "\"id\":{$t->id}"));
    $block = fn($e) => collect($e->getFieldValue('vizy')->getNodes())->first(fn($n) => $n instanceof \verbb\vizy\nodes\VizyBlock)->getBlockElement($e);
    replaceRoundTrip(Entry::find()->title('Page via Vizy block')->one(), $ctx['target'], 'Vizy block inner field', fn($e, $t) => array_map('intval', $block($e)->getFieldValue('relatedEntries')->ids()) === [$t->id]);
};
