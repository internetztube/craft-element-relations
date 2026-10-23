<?php

declare(strict_types=1);

/**
 * Craft core nested/compound fields, relating to Page C through the Entries field ("relatedEntries") in their own
 * field layout: a Content Block field and an Addresses field on Pages (the relation lives on the nested element and
 * must be attributed to the owner entry), and a Users field relating "Page via Users field" to the admin.
 * (Craft 5's global sets, categories and tags exist in Craft 6 only in the Yii2 adapter.)
 */

use CraftCms\Cms\Field\Addresses;
use CraftCms\Cms\Field\ContentBlock;
use CraftCms\Cms\Field\Elements\ContentBlock as ContentBlockElement;
use CraftCms\Cms\Field\Users;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\Support\Facades\Addresses as AddressesService;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\User\Elements\User;

return function (array $ctx): void {
    $related = Fields::getFieldByHandle('relatedEntries') ?? throw new RuntimeException('relatedEntries');
    $target = [$ctx['target']->id];

    // Content Block
    if (!Fields::getFieldByHandle('contentBlock')) {
        $field = new ContentBlock(['name' => 'Content Block', 'handle' => 'contentBlock']);
        $field->setFieldLayout(layoutFor(ContentBlockElement::class, [$related]));
        Fields::saveField($field) or fail($field);
        Fields::refreshFields();
    }
    addFieldToLayout(Fields::getFieldByHandle('contentBlock') ?? throw new RuntimeException('contentBlock'), $ctx['entryType']);
    ensureEntry('Page via Content Block', ['contentBlock' => ['fields' => ['relatedEntries' => $target]]]);

    // Addresses: the address field layout is global (one per install)
    $addressLayout = AddressesService::getFieldLayout();
    if (!$addressLayout->getFieldByHandle('relatedEntries')) {
        $addressLayout->getTabs()[0]->add(CustomField::make($related));
        AddressesService::saveFieldLayout($addressLayout) or fail($addressLayout);
    }
    addFieldToLayout(ensureField(Addresses::class, 'addresses', 'Addresses'), $ctx['entryType']);
    ensureEntry('Page via Address', ['addresses' => [
        'new1' => ['title' => 'HQ', 'countryCode' => 'AT', 'fields' => ['relatedEntries' => $target]],
    ]]);

    // Users field -> admin
    addFieldToLayout(ensureField(Users::class, 'users', 'Users'), $ctx['entryType']);
    $admin = User::find()->admin()->username('admin')->one() ?? throw new RuntimeException('admin');
    ensureEntry('Page via Users field', ['users' => [$admin->id]]);
};
