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
    // an asset index prefetches the settings usages of its page, in its site (SEOmatic's settings are per site)
    RelationsModel::prefetch([(int)$asset->id], 5, (int)$siteId);
    $prefetched = (new RelationsModel($asset->id, (int)$siteId))->forTargetSite();
    $queried = (new RelationsModel($asset->id, (int)$siteId))->forTargetSite();
    RelationsModel::forgetPrefetched();
    check($prefetched->getIsUsedInSeomaticGlobalSettings() && count($prefetched->getGlobalUsages()) === count($queried->getGlobalUsages()), 'an index page prefetches the global settings usages of its site');
    // a section's Content SEO settings (a content meta bundle) are a usage too, named after the section
    $pages = Craft::$app->getEntries()->getSectionByHandle('pages');
    $content = $bundles->getMetaBundleBySourceId('section', (int)$pages->id, $siteId) ?? throw new RuntimeException('no content bundle for Pages');
    $imagesBefore = $content->metaBundleSettings->seoImageIds;
    $content->metaBundleSettings->seoImageIds = [$asset->id];
    $bundles->updateMetaBundle($content, $siteId);
    $contentUsages = array_values(array_filter((new RelationsModel($asset->id, $asset->siteId))->getGlobalUsages(), fn($u) => str_contains($u->label, (string)$pages->name)));
    check($contentUsages && str_contains($contentUsages[0]->label, Craft::t('element-relations', 'seomatic-content', ['name' => $pages->name])) && str_contains((string)$contentUsages[0]->getCpEditUrl(), "seomatic/edit-content/general/section/$pages->handle"), "a section's Content SEO image is listed under the section, linking its settings (" . ($contentUsages[0]->label ?? 'none') . ')');
    $content->metaBundleSettings->seoImageIds = $imagesBefore;
    $bundles->updateMetaBundle($content, $siteId);
    check(!array_filter((new RelationsModel($asset->id, $asset->siteId))->getGlobalUsages(), fn($u) => str_contains($u->label, (string)$pages->name)), 'and is gone when the image is removed there');
    $relationsField = Craft::$app->getFields()->getFieldByHandle('relations');
    check(str_contains($relationsField->getPreviewHtml(new RelationsModel($asset->id, $asset->siteId), $asset), Craft::t('element-relations', 'field-value-seomatic-global')), 'the index column notes the use in the global settings');
    $sort = Craft::$app->getFields()->getFieldByHandle('relations')->getSortOption();
    // the sort counts in the index's site, which element index queries join as `elements_sites`
    $counted = (new craft\db\Query())->select($sort['orderBy'])->from(['elements' => craft\db\Table::ELEMENTS])
        ->innerJoin(['elements_sites' => craft\db\Table::ELEMENTS_SITES], '[[elements_sites.elementId]] = [[elements.id]]')
        ->where(['elements.id' => $asset->id, 'elements_sites.siteId' => $asset->siteId])->scalar();
    check((int)$counted > (int)(new RelationsModel($asset->id, $asset->siteId))->forTargetSite()->getCountFast([]), 'the sort count includes the non-element usages');

    // used only by another site's SEOmatic settings: in this site "Used in other sites", never "Unused"
    $deSiteId = Craft::$app->getSites()->getSiteByHandle('de')?->id;
    if ($deSiteId) {
        $deBundle = $bundles->getGlobalMetaBundle($deSiteId);
        $tmp = tempnam(sys_get_temp_dir(), 'er') . '.png';
        file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $onlyDe = new \craft\elements\Asset(['tempFilePath' => $tmp, 'filename' => 'seomatic-de-only.png', 'newFolderId' => Craft::$app->getAssets()->getRootFolderByVolumeId($asset->volumeId)->id, 'volumeId' => $asset->volumeId, 'avoidFilenameConflicts' => true]);
        $onlyDe->setScenario(\craft\elements\Asset::SCENARIO_CREATE);
        Craft::$app->getElements()->saveElement($onlyDe) or throw new RuntimeException(json_encode($onlyDe->getErrors()));
        $deImagesBefore = $deBundle->metaBundleSettings->seoImageIds;
        $deBundle->metaBundleSettings->seoImageIds = [$onlyDe->id];
        $bundles->updateMetaBundle($deBundle, $deSiteId);
        $inPrimary = (new RelationsModel($onlyDe->id, (int)Craft::$app->getSites()->getPrimarySite()->id))->forTargetSite();
        $field = Craft::$app->getFields()->getFieldByHandle('relations');
        $previewOnlyDe = withWebRequest(fn() => $field->getPreviewHtml(new RelationsModel($onlyDe->id, (int)$onlyDe->siteId), $onlyDe));
        check(($inPrimary->getOtherSiteCounts()[$deSiteId] ?? 0) >= 1 && !str_contains($previewOnlyDe, Craft::t('element-relations', 'field-value-unused')) && str_contains($previewOnlyDe, Craft::t('element-relations', 'preview-other-sites')), 'an asset only the German SEOmatic settings use: "Used in other sites" in English, never "Unused"');
        $onlyDeInDe = \craft\elements\Asset::find()->id($onlyDe->id)->siteId($deSiteId)->one();
        $inputOnlyDe = withWebRequest(fn() => $field->getInputHtml(new RelationsModel($onlyDe->id, $deSiteId), $onlyDeInDe));
        check(!str_contains($inputOnlyDe, 'class="element-index') && str_contains($inputOnlyDe, Craft::t('element-relations', 'field-headline-global')) && str_contains($inputOnlyDe, 'class="chip'), 'in German, only plugin settings use it: their chips, no empty list');
        $deBundle->metaBundleSettings->seoImageIds = $deImagesBefore;
        $bundles->updateMetaBundle($deBundle, $deSiteId);
        Craft::$app->getElements()->deleteElement($onlyDe, true);
    }

    replaceRoundTrip($entry, $asset, 'SEOmatic SEO image', fn($e, $t) => array_map('intval', (array)$e->getFieldValue('seo')->metaBundleSettings->seoImageIds) === [$t->id]);
    replaceRoundTrip(Entry::find()->title('Page via SEOmatic (social)')->one(), $asset, 'SEOmatic Twitter/OG images', fn($e, $t) => array_map('intval', (array)$e->getFieldValue('seo')->metaBundleSettings->ogImageIds) === [$t->id]);
};
