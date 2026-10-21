<?php

use craft\db\Query;
use craft\elements\Entry;

return function (array $ctx): void {
    $rows = fn(string $title) => (new Query())->select(['targetElementId'])->from('{{%elementrelations_cache}}')
        ->where(['sourceElementId' => Entry::find()->title($title)->one()?->id])->column();

    $social = Entry::find()->title('Page via SEO')->one()?->getFieldValue('etherSeo')->social ?? [];
    $ids = array_map(fn($s) => (int)$s->imageId, $social);
    check($ids === ['twitter' => $ctx['asset']->id, 'facebook' => $ctx['asset']->id], 'SEO (Ether): social imageIds round-trip (got ' . json_encode($ids) . ')');
    checkRelation($ctx['asset'], 'Page via SEO', 'SEO (Ether) social image');
    check(array_map('intval', $rows('Page via SEO')) === [$ctx['asset']->id], 'SEO (Ether): one cache row although both networks use the asset');
    check($rows('Page via SEO (no image)') === [], 'SEO (Ether): no image relates to nothing');
};
