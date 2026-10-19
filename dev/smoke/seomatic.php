<?php

use craft\elements\Entry;
use internetztube\elementRelations\models\RelationsModel;

return function (array $ctx): void {
    $asset = $ctx['asset'];
    $entry = Entry::find()->title('Page via SEOmatic')->one();
    $ids = $entry->getFieldValue('seo')->metaBundleSettings->seoImageIds;
    check(array_map('intval', $ids) === [$asset->id], 'field stores seoImageIds (got ' . json_encode($ids) . ')');
    checkRelation($asset, 'Page via SEOmatic', 'SEOmatic field');
    $social = Entry::find()->title('Page via SEOmatic (social)')->one()->getFieldValue('seo')->metaBundleSettings;
    check(array_map('intval', $social->twitterImageIds) === [$asset->id] && array_map('intval', $social->ogImageIds) === [$asset->id], 'field stores twitterImageIds and ogImageIds');
    checkRelation($asset, 'Page via SEOmatic (social)', 'SEOmatic Twitter/Open Graph image');

    check((new RelationsModel($asset->id, $asset->siteId))->getIsUsedInSeomaticGlobalSettings(), 'asset is used in global settings (Twitter image, multi-id, int ids)');
    $target = $ctx['target'];
    check(!(new RelationsModel($target->id, $target->siteId))->getIsUsedInSeomaticGlobalSettings(), 'Page C is not used in global settings');
    check(!(new RelationsModel(99999, $asset->siteId))->getIsUsedInSeomaticGlobalSettings(), 'id 99999 does not match 999999 in global settings');

    // settings changes rewrite the cache rows immediately (SEOmatic saves its meta bundle record)
    $bundles = \nystudio107\seomatic\Seomatic::$plugin->metaBundles;
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $bundle = $bundles->getGlobalMetaBundle($siteId);
    $bundle->metaBundleSettings->twitterImageIds = [999999];
    $bundles->updateMetaBundle($bundle, $siteId);
    check(!(new RelationsModel($asset->id, $asset->siteId))->getIsUsedInSeomaticGlobalSettings(), 'removing the image from the global settings drops the usage');
    $bundle->metaBundleSettings->twitterImageIds = [999999, $asset->id];
    $bundles->updateMetaBundle($bundle, $siteId);
    check((new RelationsModel($asset->id, $asset->siteId))->getIsUsedInSeomaticGlobalSettings(), 'adding it back restores the usage');
    $sort = Craft::$app->getFields()->getFieldByHandle('relations')->getSortOption();
    $counted = (new craft\db\Query())->select($sort['orderBy'])->from(['elements' => craft\db\Table::ELEMENTS])->where(['elements.id' => $asset->id])->scalar();
    check((int)$counted > (int)(new RelationsModel($asset->id, $asset->siteId))->getCountFast([]), 'the sort count includes the non-element usages');
};
