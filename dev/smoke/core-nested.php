<?php

use craft\elements\Address;
use craft\elements\ContentBlock;
use craft\elements\Entry;
use craft\elements\User;
use internetztube\elementRelations\models\RelationsModel;

return function (array $ctx): void {
    $target = $ctx['target'];
    $sourceIds = (new RelationsModel($target->id, $target->siteId))->getSourceElementIds([]);

    checkRelation($target, 'Page via Content Block', 'Content Block');
    $block = ContentBlock::find()->owner(Entry::find()->title('Page via Content Block')->one())->status(null)->one();
    check($block !== null && !in_array($block->id, $sourceIds, true), 'content block element itself is not a source (only the owner)');

    checkRelation($target, 'Page via Address', 'Addresses field');
    $address = Address::find()->owner(Entry::find()->title('Page via Address')->one())->status(null)->one();
    check($address !== null && !in_array($address->id, $sourceIds, true), 'address itself is not a source (only the owner)');

    checkRelation($target, 'Footer', 'global set');
    checkRelation($target, 'Test Category', 'category');
    checkRelation($target, 'Test Tag', 'tag');
    checkRelation(User::find()->username('admin')->one(), 'Page via Users field', 'Users field');
};
