<?php

declare(strict_types=1);

use CraftCms\Cms\Address\Elements\Address;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Elements\ContentBlock;
use CraftCms\Cms\User\Elements\User;
use Internetztube\ElementRelations\Models\RelationsModel;

return function (array $ctx): void {
    $target = $ctx['target'];
    $sourceIds = (new RelationsModel((int)$target->id, (int)$target->siteId))->getSourceElementIds([]);

    checkRelation($target, 'Page via Content Block', 'Content Block');
    $blockOwner = Entry::find()->title('Page via Content Block')->one() ?? throw new RuntimeException('Page via Content Block');
    $block = ContentBlock::find()->owner($blockOwner)->status(null)->one();
    check($block !== null && !in_array($block->id, $sourceIds, true), 'content block element itself is not a source (only the owner)');
    replaceRoundTrip($blockOwner, $target, 'Entries field inside a Content Block', fn($e, $t) => array_map('intval', $e->getFieldValue('contentBlock')->getFieldValue('relatedEntries')->ids()) === [(int)$t->id]);

    checkRelation($target, 'Page via Address', 'Addresses field');
    $addressOwner = Entry::find()->title('Page via Address')->one() ?? throw new RuntimeException('Page via Address');
    $address = Address::find()->owner($addressOwner)->status(null)->one();
    check($address !== null && !in_array($address->id, $sourceIds, true), 'address itself is not a source (only the owner)');
    replaceRoundTrip($addressOwner, $target, 'Entries field inside an address', fn($e, $t) => array_map('intval', $e->getFieldValue('addresses')->one()?->getFieldValue('relatedEntries')->ids() ?? []) === [(int)$t->id]);

    $admin = User::find()->username('admin')->one() ?? throw new RuntimeException('admin');
    checkRelation($admin, 'Page via Users field', 'Users field');
};
