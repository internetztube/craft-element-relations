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

    $rawJson = fn($e) => json_encode($e->getFieldValue('vizy')->getRawNodes());
    $link = Entry::find()->title('Page via Vizy link')->one();
    replaceRoundTrip($link, $ctx['target'], 'Vizy link mark', fn($e, $t) => str_contains($rawJson($e), "#entry:{$t->id}@"));
    replaceRoundTrip($link, $ctx['asset'], 'Vizy image node', fn($e, $t) => str_contains($rawJson($e), "#asset:{$t->id}:url") && str_contains($rawJson($e), "\"id\":{$t->id}"));
    $block = fn($e) => collect($e->getFieldValue('vizy')->getNodes())->first(fn($n) => $n instanceof \verbb\vizy\nodes\VizyBlock)->getBlockElement($e);
    replaceRoundTrip(Entry::find()->title('Page via Vizy block')->one(), $ctx['target'], 'Vizy block inner field', fn($e, $t) => array_map('intval', $block($e)->getFieldValue('relatedEntries')->ids()) === [$t->id]);
};
