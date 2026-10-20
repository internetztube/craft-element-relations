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

    // older data holds the image as an id list or `[['id' => …]]` (SocialData::getImage() reads both)
    $page = Entry::find()->title('Page via SEO')->one();
    foreach ([[(string)$ctx['asset']->id], [['id' => $ctx['asset']->id]]] as $shape) {
        $older = Entry::find()->id($page->id)->one();
        foreach ($older->getFieldValue('etherSeo')->social as $network) {
            $network->imageId = $shape;
        }
        $records = \internetztube\elementRelations\services\extractors\FieldExtractorEtherSeoService::getRelations(Craft::$app->getFields()->getFieldByHandle('etherSeo'), $older, new \internetztube\elementRelations\records\ElementRelationsCacheRecord());
        check(array_map(fn($record) => (int)$record->targetElementId, $records ?: []) === [(int)$ctx['asset']->id], 'SEO (Ether): an image id stored as ' . json_encode($shape) . ' relates too');
    }

    $imageIds = fn($e) => array_map(fn($s) => (int)$s->imageId, $e->getFieldValue('etherSeo')->social);
    replaceRoundTrip(Entry::find()->title('Page via SEO')->one(), $ctx['asset'], 'SEO (Ether) social images', fn($e, $t) => $imageIds($e) === ['twitter' => $t->id, 'facebook' => $t->id]);
};
