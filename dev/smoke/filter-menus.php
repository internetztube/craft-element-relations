<?php
/** The `elementTypes` and `inSites` params of the Usage query, and the field's type dropdown (it has no site dropdown). */

use craft\elements\Entry;
use internetztube\elementRelations\elements\Usage;
use internetztube\elementRelations\models\RelationsModel;

return function (array $ctx): void {
    $target = $ctx['target'];
    $model = new RelationsModel($target->id, $target->siteId);
    $counts = $model->getCountsByType();
    check(array_sum($counts) === $model->getCountFast([]), 'getCountsByType() sums up to countFast (' . json_encode($counts) . ')');
    foreach ($counts as $type => $count) {
        $unified = (int)Usage::find()->usesElement($target)->elementTypes($type)->status(null)->drafts(null)->site('*')->count();
        $own = (int)$type::find()->usesElement($target)->status(null)->drafts(null)->site('*')->count();
        check($unified === $own, "elementTypes($type) → $unified (own query: $own)");
    }
    $viaCriteria = Craft::configure(Usage::find(), ['usesElement' => $target->id, 'elementTypes' => Entry::class, 'status' => null, 'drafts' => null, 'site' => '*']); // the counts include saved drafts
    check((int)$viaCriteria->count() === ($counts[Entry::class] ?? 0), 'elementTypes works as index criteria');

    // sites: what the site dropdown sets on the index criteria (with allSites, which the field's index always has)
    $siteCounts = $model->getCountsBySite();
    check(array_sum($counts) === $model->getCountFast([]) && max($siteCounts) <= $model->getCountFast([]) && array_sum($siteCounts) >= $model->getCountFast([]), 'the type counts sum up to countFast([]), elements; a site count is at most that (an element in two sites counts in both) (' . json_encode($siteCounts) . ')');
    foreach ($siteCounts as $siteId => $count) {
        $listed = Craft::configure(Usage::find(), ['usesElement' => $target->id, 'allSites' => true, 'inSites' => (string)$siteId, 'status' => null, 'drafts' => null])->all();
        check(count($listed) === $count && !array_filter($listed, fn($element) => (int)$element->siteId !== $siteId), "inSites($siteId) lists only that site's $count usages");
    }
    $all = (int)Craft::configure(Usage::find(), ['usesElement' => $target->id, 'allSites' => true, 'inSites' => null, 'status' => null, 'drafts' => null])->count();
    check($all === $model->getCountFast([]), 'inSites null ("All sites") lists every element once');

    $field = Craft::$app->getFields()->getFieldByHandle('relations');
    $html = withWebRequest(fn() => $field->getInputHtml($model, $target));
    $menu = fn(string $param): bool => str_contains($html, 'data-param="' . $param . '"');
    $inSite = $model->forTargetSite()->getCountsByType(); // what the field lists: the target in its own site
    if (count($inSite) > 1) {
        check($menu('elementTypes') && str_contains($html, 'data-value="' . Entry::class . '"'), 'type dropdown lists the element types');
        check(str_contains($html, 'Entries <span class="badge">' . $inSite[Entry::class] . '</span>'), 'type dropdown shows counts as Craft badges');
    } else {
        check(!$menu('elementTypes'), 'no type dropdown with a single element type');
    }
    check(!$menu('inSites'), 'no site dropdown: the field lists the element in the site it is edited in');
    $b = Entry::find()->title('Page B')->one();
    check(!str_contains(withWebRequest(fn() => $field->getInputHtml($b->getFieldValue('relations'), $b)), 'data-param="elementTypes"'), 'Page B (entries only): no type dropdown');
};
