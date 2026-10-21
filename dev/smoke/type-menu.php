<?php
/** The `elementTypes` param of the Usage query and the type dropdown of the field's index. */

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
    $viaCriteria = Craft::configure(Usage::find(), ['usesElement' => $target->id, 'elementTypes' => Entry::class, 'status' => null, 'site' => '*']);
    check((int)$viaCriteria->count() === ($counts[Entry::class] ?? 0), 'elementTypes works as index criteria');

    $field = Craft::$app->getFields()->getFieldByHandle('relations');
    $html = withWebRequest(fn() => $field->getInputHtml($model, $target));
    if (count($counts) > 1) {
        check(str_contains($html, 'data-element-relations-type-menu') && substr_count($html, 'data-type="') === count($counts) + 1, 'type dropdown lists every element type plus "All types"');
        check(str_contains($html, 'Entries <span class="badge">' . $counts[Entry::class] . '</span>'), 'type dropdown shows counts as Craft badges');
    } else {
        check(!str_contains($html, 'data-element-relations-type-menu'), 'no type dropdown with a single element type');
    }
    $b = Entry::find()->title('Page B')->one();
    check(!str_contains(withWebRequest(fn() => $field->getInputHtml($b->getFieldValue('relations'), $b)), 'data-element-relations-type-menu'), 'Page B (entries only): no type dropdown');
};
