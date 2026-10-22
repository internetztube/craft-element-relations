<?php
/** Multi-site (see dev/seed/multisite.php): per-site relations, site menu/column, counts and previews per site. */

use craft\elements\Entry;
use internetztube\elementRelations\elements\Usage;
use internetztube\elementRelations\models\RelationsModel;

return function (array $ctx): void {
    $en = Craft::$app->getSites()->getPrimarySite()->id;
    $de = Craft::$app->getSites()->getSiteByHandle('de')->id;
    $target = Entry::find()->section('multi')->title('Multi Target')->one();
    $targetDe = Entry::find()->section('multi')->title('Multi Target (de only)')->one();
    $source = Entry::find()->section('multi')->title('Multi Source')->one();
    check(Craft::$app->getIsMultiSite() && $target && $targetDe && $source, 'second site and the Multi section exist');

    $rel = new RelationsModel($target->id, $en);
    $siteIds = array_map(fn($site) => $site->id, $rel->getSites());
    sort($siteIds);
    check($siteIds === [$en, $de], 'target is used in both sites (sites: ' . implode(',', $siteIds) . ')');
    check((int)$rel->getCount([$en]) === 1 && (int)$rel->getCount([$de]) === 1 && (int)$rel->getCount([]) === 1, 'counts per site: 1 (en), 1 (de), 1 (all sites: the element once)');
    check((int)$rel->getCountFast([$en]) === 1 && (int)$rel->getCountFast([]) === 1, 'fast counts agree');

    check((int)Usage::find()->usesElement($target)->site('*')->count() === 2 && (int)Usage::find()->usesElement($target)->siteId($de)->count() === 1, 'Usage query: one row per site, filterable by site');
    check((int)Entry::find()->usesElement($target)->siteId($en)->count() === 1, 'usesElement on an entry query respects the site');

    $rows = (new craft\db\Query())->select(['sourceSiteId', 'fieldId'])->from('{{%elementrelations_cache}}')->where(['sourceElementId' => $source->id, 'targetElementId' => $target->id])->orderBy(['sourceSiteId' => SORT_ASC, 'fieldId' => SORT_ASC])->all();
    $perSite = [];
    foreach ($rows as $row) {
        $perSite[(int)$row['sourceSiteId']][] = (int)$row['fieldId'];
    }
    $plain = Craft::$app->getFields()->getFieldByHandle('relatedEntries')->id;
    $localized = Craft::$app->getFields()->getFieldByHandle('relatedEntriesLocalized')->id;
    check(($perSite[$en] ?? []) === [$plain] && ($perSite[$de] ?? []) === [$plain, $localized] || ($perSite[$de] ?? []) === [$localized, $plain], 'cache rows: en via the plain field, de via both fields');

    $viaEn = Usage::find()->usesElement($target)->siteId($en)->one()->getAttributeHtml(Usage::ATTR_FIELD);
    $viaDe = Usage::find()->usesElement($target)->siteId($de)->one()->getAttributeHtml(Usage::ATTR_FIELD);
    check($viaEn === 'Related Entries' && str_contains($viaDe, 'Related Entries (per site)') && str_contains($viaDe, 'Related Entries,'), 'Field column lists the fields of that site');
    check(Usage::find()->usesElement($target)->siteId($de)->one()->getAttributeHtml(Usage::ATTR_SITE) === 'German', 'Site column names the site');
    check(!in_array(Usage::ATTR_SITE, Usage::defaultTableAttributes('*'), true) && isset(Usage::tableAttributes()[Usage::ATTR_SITE]), 'no Site column by default (the list is the element in its own site); the View menu offers it');

    // the field lists the usages of the element in the site it is edited in (its version there)
    $inEn = $rel->forTargetSite();
    $inDe = (new RelationsModel($target->id, $de))->forTargetSite();
    check($inEn->getCountFast([]) === 1 && $inDe->getCountFast([]) === 1 && $inEn->getOtherSiteCounts() === [$de => 1] && $inDe->getOtherSiteCounts() === [$en => 1], 'per site of the target: 1 usage of the English version, 1 of the German one; each names the other site');
    check((int)Usage::find()->allSites()->usesElement($target->id)->usesElementSite($de)->count() === 1 && (int)Craft::configure(Usage::find(), ['usesElement' => $target->id, 'usesElementSite' => '0', 'allSites' => true])->count() === 0, 'usesElementSite narrows the Usage query to the target in that site');

    check((int)Usage::find()->usesElement($target)->siteId($en)->allSites()->count() === 1 && (int)Usage::find()->usesElement($target)->siteId($en)->allSites()->one()->siteId === $en && (int)Usage::find()->usesElement($target)->siteId($de)->allSites()->one()->siteId === $de, 'allSites overrides the siteId the index JS always sends: the element once, shown in the site asked for');
    check(Usage::find()->usesElement($target)->siteId($en)->allSites()->one()->getAttributeHtml(Usage::ATTR_SITE) === 'English, German', 'the Site column names every site it is used in');
    $field = Craft::$app->getFields()->getFieldByHandle('relations');
    Craft::$app->getView()->clear();
    $input = withWebRequest(fn() => $field->getInputHtml($target->getFieldValue('relations'), $target));
    $js = implode("\n", array_merge(...array_values(Craft::$app->getView()->js ?: [[]])));
    check(!str_contains($input, 'sitemenubtn') && str_contains($js, '"allSites":true') && str_contains($js, '"usesElementSite":' . $en), 'the field lists the English version\'s usages, from every site (no site menu, allSites and usesElementSite criteria)');
    check(str_contains($js, '"siteId":' . $en) && !str_contains($input, 'data-param="inSites"'), 'the list\'s base condition names the site; no site dropdown');
    check(str_contains($input, 'data-element-relations-other-sites') && str_contains($input, 'German') && str_contains($input, '1 usage') && str_contains(html_entity_decode($input), 'site=de'), 'below the list: the other sites whose version is used, linking to the element there');
    check(str_contains(html_entity_decode($input), '"attr":"elementRelationsSite"'), 'the index can sort by site');

    // used on the other site only
    $relDe = new RelationsModel($targetDe->id, $en);
    check((int)$relDe->getCount([$en]) === 0 && (int)$relDe->getCount([$de]) === 1 && $relDe->getIsInUse([]) && array_values(array_map(fn($site) => $site->id, $relDe->getSites())) === [$de], 'target used on the German site only: 0 (en), 1 (de), in use, sites = [de]');
    $preview = withWebRequest(fn() => $field->getPreviewHtml($targetDe->getFieldValue('relations'), $targetDe));
    check(!str_contains($preview, 'Multi Source') && !str_contains($preview, 'Unused') && str_contains($preview, Craft::t('element-relations', 'preview-other-sites')), 'index column on en: no usage of the English version, so "used in other sites" (not "Unused")');
    $inputDeOnly = withWebRequest(fn() => $field->getInputHtml($targetDe->getFieldValue('relations'), $targetDe));
    check(!str_contains($inputDeOnly, 'class="element-index') && str_contains($inputDeOnly, 'data-element-relations-other-sites') && !str_contains($inputDeOnly, Craft::t('element-relations', 'field-value-unused')), 'the field on en: no list, the German site under "also used in these other sites", not "Unused"');
    $targetDeOnDe = Entry::find()->id($targetDe->id)->siteId($de)->one();
    $preview = withWebRequest(fn() => $field->getPreviewHtml($targetDeOnDe->getFieldValue('relations'), $targetDeOnDe));
    check(str_contains($preview, 'Multi Source'), 'index column on de lists the source');

    // removing the per-site relation on de must not touch en
    $sourceDe = Entry::find()->id($source->id)->siteId($de)->one();
    $sourceDe->setFieldValue('relatedEntriesLocalized', []);
    Craft::$app->getElements()->saveElement($sourceDe) or throw new RuntimeException(json_encode($sourceDe->getErrors()));
    Craft::$app->trigger(yii\base\Application::EVENT_AFTER_REQUEST);
    Craft::$app->getQueue()->run();
    check((int)$relDe->getCount([]) === 0 && (int)$rel->getCount([$de]) === 1 && (int)$rel->getCount([$en]) === 1, 'clearing the localized field on de removes only that relation');
    $sourceDe->setFieldValue('relatedEntriesLocalized', [$target->id, $targetDe->id]);
    Craft::$app->getElements()->saveElement($sourceDe) or throw new RuntimeException(json_encode($sourceDe->getErrors()));
    Craft::$app->trigger(yii\base\Application::EVENT_AFTER_REQUEST);
    Craft::$app->getQueue()->run();
    check((int)$relDe->getCount([]) === 1, 'restored');

    // a deleted element leaves no rows behind, in any site (a full rebuild would not bring them back either)
    $rowsOf = fn(int $id, ?int $siteId = null): int => (int)(new craft\db\Query())->from('{{%elementrelations_cache}}')
        ->where(['or', ['sourceElementId' => $id], ['sourcePrimaryOwnerId' => $id]])->andFilterWhere(['sourceSiteId' => $siteId])->count();
    $temp = function () use ($source, $target, $targetDe, $de): Entry {
        $entry = new Entry(['sectionId' => $source->sectionId, 'typeId' => $source->typeId, 'title' => 'Multi temp ' . uniqid()]);
        $entry->setFieldValue('relatedEntries', [$target->id]);
        Craft::$app->getElements()->saveElement($entry) or throw new RuntimeException(json_encode($entry->getErrors()));
        $entryDe = Entry::find()->id($entry->id)->siteId($de)->one();
        $entryDe->setFieldValue('relatedEntriesLocalized', [$targetDe->id]);
        Craft::$app->getElements()->saveElement($entryDe) or throw new RuntimeException(json_encode($entryDe->getErrors()));
        Craft::$app->trigger(yii\base\Application::EVENT_AFTER_REQUEST);
        Craft::$app->getQueue()->run();
        return $entry;
    };
    $doomed = $temp();
    check($rowsOf($doomed->id, $en) > 0 && $rowsOf($doomed->id, $de) > 0, 'a new entry has rows in both sites');
    Craft::$app->getElements()->deleteElement($doomed);
    check($rowsOf($doomed->id, $en) > 0 && $rowsOf($doomed->id, $de) > 0, 'trashed: its rows stay in both sites (it can be restored)');
    Craft::$app->getElements()->deleteElement($doomed, true);
    check($rowsOf($doomed->id) === 0, 'deleted for good: no rows left in any site, not only the site it was loaded in');
    $halved = $temp();
    Craft::$app->getElements()->deleteElementForSite(Entry::find()->id($halved->id)->siteId($de)->one());
    check($rowsOf($halved->id, $de) === 0 && $rowsOf($halved->id, $en) > 0, 'deleted for German only: its German rows go, the English ones stay');
    Craft::$app->getElements()->deleteElement($halved, true);
    check($rowsOf($halved->id) === 0, 'and deleted for good: none left');
};
