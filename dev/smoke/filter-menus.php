<?php

declare(strict_types=1);

/** The `elementTypes` and `inSites` params of the Usage query, and the field's type dropdown (it has no site dropdown). */

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Typecast;
use CraftCms\Cms\User\Elements\User;
use Internetztube\ElementRelations\Elements\Usage;
use Internetztube\ElementRelations\Fields\ElementRelationsField;
use Internetztube\ElementRelations\Models\RelationsModel;

return function (array $ctx): void {
    $field = Fields::getFieldByHandle('relations');
    assert($field instanceof ElementRelationsField);
    // Page C (the 4.x content had categories, tags and global sets using it, which Craft 6 has no more) and the asset
    // (used by the admin's photo, and by an entry for this test): the dropdown appears where more than one type lists
    $pages = Sections::getSectionByHandle('pages') ?? throw new RuntimeException('pages');
    foreach (Entry::find()->title('FM via asset')->status(null)->all() as $old) { // from an aborted run
        Elements::deleteElement($old, true);
    }
    $viaAsset = new Entry(['sectionId' => $pages->id, 'typeId' => $pages->getEntryTypes()[0]->id, 'title' => 'FM via asset']);
    $viaAsset->setFieldValue('relatedAssets', [$ctx['asset']->id]);
    Elements::saveElement($viaAsset) or fail($viaAsset);
    flushQueue();
    $multiType = 0;
    foreach ([$ctx['target'], $ctx['asset']] as $target) {
        assert($target instanceof ElementInterface);
        $model = new RelationsModel((int)$target->id, (int)$target->siteId);
        $counts = $model->getCountsByType();
        check(array_sum($counts) === $model->getCountFast([]), "$target: getCountsByType() sums up to countFast (" . json_encode($counts) . ')');
        foreach ($counts as $type => $count) {
            $unified = Usage::find()->usesElement($target)->elementTypes($type)->status(null)->drafts(null)->site('*')->count();
            $own = $type::find()->usesElement($target)->status(null)->drafts(null)->site('*')->count();
            check($unified === $own, "elementTypes($type) → $unified (own query: $own)");
        }
        $viaCriteria = Typecast::configure(Usage::find(), ['usesElement' => $target->id, 'elementTypes' => Entry::class, 'status' => null, 'drafts' => null, 'site' => '*']); // the counts include saved drafts
        check($viaCriteria->count() === ($counts[Entry::class] ?? 0), 'elementTypes works as index criteria');

        // sites: what the site dropdown sets on the index criteria (with allSites, which the field's index always has)
        $siteCounts = $model->getCountsBySite();
        check(array_sum($counts) === $model->getCountFast([]) && max($siteCounts) <= $model->getCountFast([]) && array_sum($siteCounts) >= $model->getCountFast([]), 'the type counts sum up to countFast([]), elements; a site count is at most that (an element in two sites counts in both) (' . json_encode($siteCounts) . ')');
        foreach ($siteCounts as $siteId => $count) {
            $listed = Usage::find()->allSites()->inSites((string)$siteId)->status(null)->drafts(null)->usesElement($target->id)->all();
            check(count($listed) === $count && !array_filter($listed, fn(ElementInterface $element): bool => (int)$element->siteId !== $siteId), "inSites($siteId) lists only that site's $count usages");
        }
        $all = Typecast::configure(Usage::find(), ['usesElement' => $target->id, 'allSites' => true, 'inSites' => null, 'status' => null, 'drafts' => null])->count();
        check($all === $model->getCountFast([]), 'inSites null ("All sites") lists every element once');

        $html = withWebRequest(fn(): string => $field->inputHtmlFor($model, $target));
        $menu = fn(string $param): bool => str_contains($html, 'data-param="' . $param . '"');
        $inSite = $model->forTargetSite()->getCountsByType(); // what the field lists: the target in its own site
        if (count($inSite) > 1) {
            $multiType++;
            $missing = array_filter(array_keys($inSite), fn(string $type): bool => !str_contains($html, 'data-value="' . $type . '"'));
            check($menu('elementTypes') && !$missing, "$target: type dropdown lists the element types that use it (" . implode(', ', array_keys($inSite)) . ')');
            check(str_contains($html, 'Entries <span class="badge">' . $inSite[Entry::class] . '</span>'), "$target: type dropdown shows counts as Craft badges");
        } else {
            check(!$menu('elementTypes'), "$target: no type dropdown with a single element type");
        }
        check(!$menu('inSites'), "$target: no site dropdown: the field lists the element in the site it is edited in");
    }
    check($multiType > 0, 'the asset is used by more than one element type (entries and the admin), so its list has the type dropdown');
    $b = Entry::find()->title('Page B')->one() ?? throw new RuntimeException('B');
    check(!str_contains(withWebRequest(fn(): string => $field->inputHtmlFor($b->getFieldValue('relations'), $b)), 'data-param="elementTypes"'), 'Page B (entries only): no type dropdown');
    Elements::deleteElement($viaAsset, true);
    flushQueue();
};
