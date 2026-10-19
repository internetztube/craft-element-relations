<?php

use craft\db\Query;
use craft\elements\Entry;

return function (array $ctx): void {
    $rows = fn(string $title) => (new Query())->select(['targetElementId'])->from('{{%elementrelations_cache}}')
        ->where(['sourceElementId' => Entry::find()->title($title)->one()?->id])->orderBy(['targetElementId' => SORT_ASC])->column();

    $links = Entry::find()->title('Page via FreeLink')->one()?->getFieldValue('freeLink');
    $ids = $links instanceof justinholtweb\freelink\models\LinkCollection ? array_map(fn($l) => $l->getElement()?->id, $links->getAll()) : null;
    check($ids === [$ctx['target']->id, $ctx['asset']->id], 'FreeLink: multi-link value round-trips (got ' . json_encode($ids) . ')');
    checkRelation($ctx['target'], 'Page via FreeLink', 'FreeLink (multi, entry)');
    checkRelation($ctx['asset'], 'Page via FreeLink', 'FreeLink (multi, asset)');
    check(count($rows('Page via FreeLink')) === 2, 'FreeLink: exactly 2 cache rows for the multi-link entry');

    $single = Entry::find()->title('Page via FreeLink (single)')->one()?->getFieldValue('freeLinkSingle');
    check($single?->getElement()?->id === $ctx['target']->id, 'FreeLink: single-link value round-trips');
    checkRelation($ctx['target'], 'Page via FreeLink (single)', 'FreeLink (single)');
    check($rows('Page via FreeLink (url)') === [], 'FreeLink: URL link relates to nothing');
};
