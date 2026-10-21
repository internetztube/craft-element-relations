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
};
