<?php

declare(strict_types=1);

/** Multi-site (see dev/seed/multisite.php): per-site relations, site menu/column, counts and previews per site. */

use CraftCms\Cms\Edition;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\HtmlStack;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Internetztube\ElementRelations\Elements\Usage;
use Internetztube\ElementRelations\Fields\ElementRelationsField;
use Internetztube\ElementRelations\Models\CacheRecord;
use Internetztube\ElementRelations\Models\RelationsModel;

use function CraftCms\Cms\t;

return function (array $ctx): void {
    $en = (int)Sites::getPrimarySite()->id;
    $de = (int)Sites::getSiteByHandle('de')?->id;
    $target = Entry::find()->section('multi')->title('Multi Target')->one();
    $targetDe = Entry::find()->section('multi')->title('Multi Target (de only)')->one();
    $source = Entry::find()->section('multi')->title('Multi Source')->one();
    check(Sites::isMultiSite() && $target && $targetDe && $source, 'second site and the Multi section exist');
    assert($target instanceof Entry && $targetDe instanceof Entry && $source instanceof Entry);

    $rel = new RelationsModel((int)$target->id, $en);
    $siteIds = array_map(fn($site) => (int)$site->id, $rel->getSites());
    sort($siteIds);
    check($siteIds === [$en, $de], 'target is used in both sites (sites: ' . implode(',', $siteIds) . ')');
    check($rel->getCount([$en]) === 1 && $rel->getCount([$de]) === 1 && $rel->getCount([]) === 1, 'counts per site: 1 (en), 1 (de), 1 (all sites: the element once)');
    check($rel->getCountFast([$en]) === 1 && $rel->getCountFast([]) === 1, 'fast counts agree');

    check(Usage::find()->usesElement($target)->site('*')->count() === 2 && Usage::find()->usesElement($target)->siteId($de)->count() === 1, 'Usage query: one row per site, filterable by site');
    check(Entry::find()->usesElement($target)->siteId($en)->count() === 1, 'usesElement on an entry query respects the site');

    $rows = DB::table(CacheRecord::TABLE)->select(['sourceSiteId', 'fieldId'])->where('sourceElementId', $source->id)->where('targetElementId', $target->id)->orderBy('sourceSiteId')->orderBy('fieldId')->get();
    $perSite = [];
    foreach ($rows as $row) {
        $perSite[(int)$row->sourceSiteId][] = (int)$row->fieldId;
    }
    $plain = (int)Fields::getFieldByHandle('relatedEntries')?->id;
    $localized = (int)Fields::getFieldByHandle('relatedEntriesLocalized')?->id;
    $deFields = $perSite[$de] ?? [];
    sort($deFields);
    $both = [$plain, $localized];
    sort($both);
    check(($perSite[$en] ?? []) === [$plain] && $deFields === $both, 'cache rows: en via the plain field, de via both fields');

    $usageIn = fn(int $siteId): \CraftCms\Cms\Element\Contracts\ElementInterface => Usage::find()->usesElement($target)->siteId($siteId)->one() ?? throw new RuntimeException("usage in $siteId");
    $viaEn = (string)$usageIn($en)->getAttributeHtml(Usage::ATTR_FIELD);
    $viaDe = (string)$usageIn($de)->getAttributeHtml(Usage::ATTR_FIELD);
    check($viaEn === 'Related Entries' && str_contains($viaDe, 'Related Entries (per site)') && str_contains($viaDe, 'Related Entries,'), "Field column lists the fields of that site ($viaEn / $viaDe)");
    check((string)$usageIn($de)->getAttributeHtml(Usage::ATTR_SITE) === 'German', 'Site column names the site');
    check(!in_array(Usage::ATTR_SITE, Usage::defaultTableAttributes('*'), true) && isset(Usage::tableAttributes()[Usage::ATTR_SITE]), 'no Site column by default (the list is the element in its own site); the View menu offers it');

    // the field lists the usages of the element in the site it is edited in (its version there)
    $inEn = $rel->forTargetSite();
    $inDe = (new RelationsModel((int)$target->id, $de))->forTargetSite();
    check($inEn->getCountFast([]) === 1 && $inDe->getCountFast([]) === 1 && $inEn->getOtherSiteCounts() === [$de => 1] && $inDe->getOtherSiteCounts() === [$en => 1], 'per site of the target: 1 usage of the English version, 1 of the German one; each names the other site');
    check(Usage::find()->allSites()->usesElement($target->id)->usesElementSite($de)->count() === 1 && Usage::find()->usesElement($target->id)->usesElementSite('0')->allSites()->count() === 0, 'usesElementSite narrows the Usage query to the target in that site');

    $allSitesIn = fn(int $siteId) => Usage::find()->usesElement($target)->siteId($siteId)->allSites();
    check($allSitesIn($en)->count() === 1 && (int)$allSitesIn($en)->one()?->siteId === $en && (int)$allSitesIn($de)->one()?->siteId === $de, 'allSites overrides the siteId the index JS always sends: the element once, shown in the site asked for');
    check((string)$allSitesIn($en)->one()?->getAttributeHtml(Usage::ATTR_SITE) === Sites::getPrimarySite()->getName() . ', German', 'the Site column names every site it is used in, primary site first (' . $allSitesIn($en)->one()?->getAttributeHtml(Usage::ATTR_SITE) . ')');
    $field = Fields::getFieldByHandle('relations');
    assert($field instanceof ElementRelationsField);
    $captured = withWebRequest(fn() => HtmlStack::capture(fn(): string => $field->inputHtmlFor($target->getFieldValue('relations'), $target)));
    $input = $captured->html;
    $js = html_entity_decode($captured->headHtml . $captured->bodyHtml . $captured->html);
    check(!str_contains($input, 'sitemenubtn') && str_contains($js, '"allSites":true') && str_contains($js, '"usesElementSite":' . $en), 'the field lists the English version\'s usages, from every site (no site menu, allSites and usesElementSite criteria)');
    check(str_contains($js, '"siteId":' . $en) && !str_contains($input, 'data-param="inSites"'), 'the list\'s base condition names the site; no site dropdown');
    check(str_contains($input, 'data-element-relations-other-sites') && str_contains($input, 'German') && str_contains($input, '1 usage') && str_contains(html_entity_decode($input), 'site=de'), 'below the list: the other sites whose version is used, linking to the element there');
    check(str_contains($js, '"attr":"elementRelationsSite"'), 'the index can sort by site');

    // used on the other site only
    $relDe = new RelationsModel((int)$targetDe->id, $en);
    check($relDe->getCount([$en]) === 0 && $relDe->getCount([$de]) === 1 && $relDe->getIsInUse([]) && array_values(array_map(fn($site) => (int)$site->id, $relDe->getSites())) === [$de], 'target used on the German site only: 0 (en), 1 (de), in use, sites = [de]');
    $preview = withWebRequest(fn() => $field->getPreviewHtml($targetDe->getFieldValue('relations'), $targetDe));
    check(!str_contains($preview, 'Multi Source') && !str_contains($preview, 'Unused') && str_contains($preview, t('preview-other-sites', category: 'element-relations')) && str_contains($preview, '<craft-badge') && str_contains($preview, 'German'), 'index column on en: no usage of the English version, so a "Used in other sites" status label and German (not "Unused")');
    $inputDeOnly = withWebRequest(fn() => $field->inputHtmlFor($targetDe->getFieldValue('relations'), $targetDe));
    check(!str_contains($inputDeOnly, 'class="element-index') && str_contains($inputDeOnly, 'data-element-relations-other-sites') && !str_contains($inputDeOnly, t('field-value-unused', category: 'element-relations')), 'the field on en: no list, the German site under "also used in these other sites", not "Unused"');
    check(str_contains($inputDeOnly, t('preview-other-sites', category: 'element-relations')) && str_contains($inputDeOnly, '<craft-badge') && !str_contains($inputDeOnly, t('field-headline-other-sites', category: 'element-relations')) && str_contains($inputDeOnly, '<craft-chip'), 'nothing uses it here: a "Used in other sites" status label (where "Unused" would be), not "Also used…", and the sites as Craft chips (Craft 6: <craft-badge>, <craft-chip>)');
    // an editor who may not edit the German site: still not "Unused", the site named without a link (Craft Pro, in
    // memory: in Solo everyone may do everything)
    $editionBefore = Context::getHidden(Edition::class);
    Context::addHidden(Edition::class, Edition::Pro);
    try {
        $inputNoDe = withWebRequest(fn() => $field->inputHtmlFor($targetDe->getFieldValue('relations'), $targetDe), new User(['username' => 'smoke-en-only', 'admin' => false]));
    } finally {
        $editionBefore === null ? Context::forgetHidden(Edition::class) : Context::addHidden(Edition::class, $editionBefore);
    }
    check(str_contains($inputNoDe, 'German') && !str_contains(html_entity_decode($inputNoDe), 'site=de') && !str_contains($inputNoDe, t('field-value-unused', category: 'element-relations')), 'an editor without the German site: "Used in other sites: German" without a link, never "Unused"');
    // sorting the English index by the field: unused, then used in other sites only, then used here
    $sortSql = $field->getSortOption()['orderBy'];
    $sortedEn = Entry::find()->section('multi')->siteId($en)->status(null)->id([$source->id, $targetDe->id, $target->id])->orderBy($sortSql, 'asc')->ids();
    check(array_map('intval', $sortedEn) === [(int)$source->id, (int)$targetDe->id, (int)$target->id], 'sorting by the field: unused (Multi Source), then used in other sites only (Multi Target (de only)), then used here (Multi Target)');
    $targetDeOnDe = Entry::find()->id($targetDe->id)->siteId($de)->one() ?? throw new RuntimeException('de only, on de');
    $preview = withWebRequest(fn() => $field->getPreviewHtml($targetDeOnDe->getFieldValue('relations'), $targetDeOnDe));
    check(str_contains($preview, 'Multi Source'), 'index column on de lists the source');

    // removing the per-site relation on de must not touch en
    $sourceDe = Entry::find()->id($source->id)->siteId($de)->one() ?? throw new RuntimeException('source on de');
    $sourceDe->setFieldValue('relatedEntriesLocalized', []);
    Elements::saveElement($sourceDe) or fail($sourceDe);
    flushQueue();
    check($relDe->getCount([]) === 0 && $rel->getCount([$de]) === 1 && $rel->getCount([$en]) === 1, 'clearing the localized field on de removes only that relation');
    $sourceDe->setFieldValue('relatedEntriesLocalized', [$target->id, $targetDe->id]);
    Elements::saveElement($sourceDe) or fail($sourceDe);
    flushQueue();
    check($relDe->getCount([]) === 1, 'restored');

    // a deleted element leaves no rows behind, in any site (a full rebuild would not bring them back either)
    $rowsOf = fn(int $id, ?int $siteId = null): int => DB::table(CacheRecord::TABLE)
        ->where(fn($q) => $q->where('sourceElementId', $id)->orWhere('sourcePrimaryOwnerId', $id))
        ->when($siteId !== null, fn($q) => $q->where('sourceSiteId', $siteId))->count();
    $temp = function () use ($source, $target, $targetDe, $de): Entry {
        $entry = new Entry(['sectionId' => $source->sectionId, 'typeId' => $source->typeId, 'title' => 'Multi temp ' . uniqid()]);
        $entry->setFieldValue('relatedEntries', [$target->id]);
        Elements::saveElement($entry) or fail($entry);
        $entryDe = Entry::find()->id($entry->id)->siteId($de)->one() ?? throw new RuntimeException('temp on de');
        $entryDe->setFieldValue('relatedEntriesLocalized', [$targetDe->id]);
        Elements::saveElement($entryDe) or fail($entryDe);
        flushQueue();
        return $entry;
    };
    $doomed = $temp();
    check($rowsOf((int)$doomed->id, $en) > 0 && $rowsOf((int)$doomed->id, $de) > 0, 'a new entry has rows in both sites');
    Elements::deleteElement($doomed);
    check($rowsOf((int)$doomed->id, $en) > 0 && $rowsOf((int)$doomed->id, $de) > 0, 'trashed: its rows stay in both sites (it can be restored)');
    Elements::deleteElement($doomed, true);
    check($rowsOf((int)$doomed->id) === 0, 'deleted for good: no rows left in any site, not only the site it was loaded in');
    $halved = $temp();
    Elements::deleteElementForSite(Entry::find()->id($halved->id)->siteId($de)->one() ?? throw new RuntimeException('halved on de'));
    check($rowsOf((int)$halved->id, $de) === 0 && $rowsOf((int)$halved->id, $en) > 0, 'deleted for German only: its German rows go, the English ones stay');
    Elements::deleteElement($halved, true);
    check($rowsOf((int)$halved->id) === 0, 'and deleted for good: none left');

    // the per-site relation, replaced and rolled back on the German site only
    replaceRoundTrip($sourceDe, $targetDe, 'Localized Entries field, German site', fn($e, $t) => array_map('intval', $e->getFieldValue('relatedEntriesLocalized')->ids()) === [(int)$target->id, (int)$t->id]);
};
