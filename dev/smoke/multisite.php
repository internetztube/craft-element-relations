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
    check((int)$rel->getCount([$en]) === 1 && (int)$rel->getCount([$de]) === 1 && (int)$rel->getCount([]) === 2, 'counts per site: 1 (en), 1 (de), 2 (all sites)');
    check((int)$rel->getCountFast([$en]) === 1 && (int)$rel->getCountFast([]) === 2, 'fast counts agree');

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
    check(in_array(Usage::ATTR_SITE, Usage::defaultTableAttributes('*'), true), 'Site column is shown by default on multi-site installs');

    $field = Craft::$app->getFields()->getFieldByHandle('relations');
    $input = withWebRequest(fn() => $field->getInputHtml($target->getFieldValue('relations'), $target));
    check(str_contains($input, 'sitemenubtn'), 'the index shows the site menu');
    $noSiteMenu = clone $field;
    $noSiteMenu->showSiteMenu = false;
    $input = withWebRequest(fn() => $noSiteMenu->getInputHtml($target->getFieldValue('relations'), $target));
    check(!str_contains($input, 'sitemenubtn'), 'field setting: site menu hidden');

    // used on the other site only
    $relDe = new RelationsModel($targetDe->id, $en);
    check((int)$relDe->getCount([$en]) === 0 && (int)$relDe->getCount([$de]) === 1 && $relDe->getIsInUse([]) && array_values(array_map(fn($site) => $site->id, $relDe->getSites())) === [$de], 'target used on the German site only: 0 (en), 1 (de), in use, sites = [de]');
    $preview = withWebRequest(fn() => $field->getPreviewHtml($targetDe->getFieldValue('relations'), $targetDe));
    check(str_contains($preview, Craft::t('element-relations', 'preview-other-sites')) && !str_contains($preview, 'Unused'), 'index column on en says it is used in other sites');
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
};
