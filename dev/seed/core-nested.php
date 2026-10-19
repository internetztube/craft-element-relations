<?php
/**
 * Craft core element types and nested/compound fields, all relating to Page C through the Entries field
 * ("relatedEntries") in their own field layout: a Content Block field and an Addresses field on Pages
 * (relation lives on the nested element, must be attributed to the owner entry), global set "Footer",
 * category "Test Category", tag "Test Tag", and a Users field relating "Page via Users field" to the admin.
 */

use craft\elements\Address;
use craft\elements\Category;
use craft\elements\ContentBlock as ContentBlockElement;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Addresses;
use craft\fields\ContentBlock;
use craft\fields\Users;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\TagGroup;

return function (array $ctx): void {
    $related = Craft::$app->getFields()->getFieldByHandle('relatedEntries');
    $target = [$ctx['target']->id];
    $relatedLayout = function (string $type) use ($related): FieldLayout {
        $layout = new FieldLayout(['type' => $type]);
        $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
        $tab->setElements([new CustomField($related)]);
        $layout->setTabs([$tab]);
        return $layout;
    };

    // Content Block (Craft 5.8+)
    if (!Craft::$app->getFields()->getFieldByHandle('contentBlock')) {
        $field = new ContentBlock(['name' => 'Content Block', 'handle' => 'contentBlock']);
        $field->setFieldLayout($relatedLayout(ContentBlockElement::class));
        Craft::$app->getFields()->saveField($field) or fail($field);
    }
    addFieldToLayout(Craft::$app->getFields()->getFieldByHandle('contentBlock'), $ctx['entryType']);
    ensureEntry('Page via Content Block', ['contentBlock' => ['fields' => ['relatedEntries' => $target]]]);

    // Addresses: the address field layout is global (one per install)
    $addresses = Craft::$app->getAddresses();
    $addressLayout = $addresses->getFieldLayout();
    if (!$addressLayout->getFieldByHandle('relatedEntries')) {
        $tab = $addressLayout->getTabs()[0];
        $tab->setElements([...$tab->getElements(), new CustomField($related)]);
        $addressLayout->setTabs($addressLayout->getTabs());
        $addresses->saveFieldLayout($addressLayout) or fail($addressLayout);
    }
    addFieldToLayout(ensureField(Addresses::class, ['name' => 'Addresses', 'handle' => 'addresses']), $ctx['entryType']);
    ensureEntry('Page via Address', ['addresses' => [
        'new1' => ['title' => 'HQ', 'countryCode' => 'AT', 'fields' => ['relatedEntries' => $target]],
    ]]);

    // Global set
    $globals = Craft::$app->getGlobals();
    if (!$globals->getSetByHandle('footer')) {
        $set = new GlobalSet(['name' => 'Footer', 'handle' => 'footer']);
        $set->setFieldLayout($relatedLayout(GlobalSet::class));
        $globals->saveSet($set) or fail($set);
    }
    $set = $globals->getSetByHandle('footer');
    $set->setFieldValue('relatedEntries', $target);
    Craft::$app->getElements()->saveElement($set) or fail($set);

    // Category
    $categories = Craft::$app->getCategories();
    if (!$group = $categories->getGroupByHandle('testCategories')) {
        $group = new CategoryGroup(['name' => 'Test Categories', 'handle' => 'testCategories']);
        $siteSettings = [];
        foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
            $siteSettings[$siteId] = new CategoryGroup_SiteSettings(['siteId' => $siteId, 'hasUrls' => false]);
        }
        $group->setSiteSettings($siteSettings);
        $group->setFieldLayout($relatedLayout(Category::class));
        $categories->saveGroup($group) or fail($group);
    }
    $category = Category::find()->group('testCategories')->title('Test Category')->status(null)->one()
        ?? new Category(['groupId' => $group->id, 'title' => 'Test Category']);
    $category->setFieldValue('relatedEntries', $target);
    Craft::$app->getElements()->saveElement($category) or fail($category);

    // Tag
    $tags = Craft::$app->getTags();
    if (!$tagGroup = $tags->getTagGroupByHandle('testTags')) {
        $tagGroup = new TagGroup(['name' => 'Test Tags', 'handle' => 'testTags']);
        $tagGroup->setFieldLayout($relatedLayout(Tag::class));
        $tags->saveTagGroup($tagGroup) or fail($tagGroup);
    }
    $tag = Tag::find()->group('testTags')->title('Test Tag')->status(null)->one()
        ?? new Tag(['groupId' => $tagGroup->id, 'title' => 'Test Tag']);
    $tag->setFieldValue('relatedEntries', $target);
    Craft::$app->getElements()->saveElement($tag) or fail($tag);

    // Users field -> admin
    addFieldToLayout(ensureField(Users::class, ['name' => 'Users', 'handle' => 'users']), $ctx['entryType']);
    ensureEntry('Page via Users field', ['users' => [User::find()->admin()->username('admin')->one()->id]]);
};
