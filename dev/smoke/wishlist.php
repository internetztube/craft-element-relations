<?php

use craft\elements\Entry;
use internetztube\elementRelations\services\ReplaceService;
use verbb\wishlist\elements\ListElement;

return function (array $ctx): void {
    checkRelation($ctx['target'], 'Admin wishlist', 'Wishlist item (shown as its list)');

    // Replace: the field lists the list, the item is what gets rewritten (elementId/elementSiteId/elementClass).
    $elements = Craft::$app->getElements();
    $list = ListElement::find()->title('Admin wishlist')->status(null)->one();
    $from = $ctx['target'];
    $target = new Entry(['sectionId' => $from->sectionId, 'typeId' => $from->typeId, 'title' => 'RT Wishlist']);
    $elements->saveElement($target) or throw new RuntimeException(json_encode($target->getErrors()));
    $reload = fn() => ListElement::find()->id($list->id)->status(null)->one();

    $result = ReplaceService::replace($from, $target, [$list->id]);
    flushQueue();
    check($result['replaced'] === 1 && !$result['skipped'], 'Wishlist: replace rewrites the item (' . json_encode($result) . ')');
    check(in_array('Admin wishlist', relatingTitles($target), true) && !in_array('Admin wishlist', relatingTitles($from), true), 'Wishlist: the relation moved to the target');
    check($reload()->getItem($target) && !$reload()->getItem($from), 'Wishlist: the item points to the target');
    $back = ReplaceService::replace($target, $from, [$list->id]);
    flushQueue();
    check($back['replaced'] === 1 && in_array('Admin wishlist', relatingTitles($from), true) && $reload()->getItem($from), 'Wishlist: replacing back restores the original');
    $elements->deleteElement($target, true);
};
