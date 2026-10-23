<?php

declare(strict_types=1);

/**
 * Categories, tags and global sets: Craft 6 has them only through its Yii2 adapter (`craftcms/yii2-adapter`, see
 * `YII2_ADAPTER=1` in dev/dev.sh). Without it this seed does nothing.
 *
 * As relating elements (the Entries field "relatedEntries" in their own field layouts, all relating to Page C): global
 * set "Footer", category "Test Category", tag "Test Tag". As targets: "Target Category" (used by "Page via Categories
 * field" and by "Page via Category link", the Link field's Category type) and "Target Tag" (used by "Page via Tags
 * field"). The category and tag groups and the global set carry the Element Relations field too.
 */

use craft\elements\Category;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\TagGroup;
use CraftCms\Cms\Field\Link;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return function (array $ctx): void {
    if (!class_exists(Category::class)) {
        echo "  skipped: craftcms/yii2-adapter is not installed\n";
        return;
    }
    // a fresh Craft 6 install has no tables for them; the adapter only registers them once the tables exist, so the
    // content follows in the second seed pass (a fresh process, see dev/dev.sh)
    $added = !Schema::hasTable('categories') || !Schema::hasTable('tags') || !Schema::hasTable('globalsets');
    if ($added) {
        foreach (['categories', 'tags', 'global-sets'] as $concept) {
            app(Kernel::class)->call("craft:add-$concept-support", ['--force' => true]);
        }
    }
    // the adapter's craft:add-tags-support (6.0.0-alpha.20) creates `tags` without a primary key, and Yii's Tag record
    // then fails to save; a site upgraded from Craft 5 has it
    if (!array_filter(Schema::getIndexes('tags'), fn(array $index): bool => (bool)$index['primary'])) {
        Schema::table('tags', fn(Blueprint $table) => $table->primary('id'));
    }
    if ($added) {
        echo "  added the adapter's tables, the second pass seeds the content\n";
        return;
    }
    $related = Fields::getFieldByHandle('relatedEntries') ?? throw new RuntimeException('relatedEntries');
    $relations = Fields::getFieldByHandle('relations') ?? throw new RuntimeException('relations');
    $target = [$ctx['target']->id];

    // Global set
    $globals = Craft::$app->getGlobals();
    if (!$globals->getSetByHandle('footer')) {
        $set = new GlobalSet(['name' => 'Footer', 'handle' => 'footer']);
        $set->getBehavior('fieldLayout')->setFieldLayout(layoutFor(GlobalSet::class, [$related, $relations])); // a Yii behavior
        $globals->saveSet($set) or fail($set);
    }
    $set = $globals->getSetByHandle('footer') ?? throw new RuntimeException('footer');
    $set->setFieldValue('relatedEntries', $target);
    Elements::saveElement($set) or fail($set);

    // Category group (with URLs: the Link field's Category type offers only such groups)
    $categories = Craft::$app->getCategories();
    if (!$group = $categories->getGroupByHandle('testCategories')) {
        $group = new CategoryGroup(['name' => 'Test Categories', 'handle' => 'testCategories']);
        $siteSettings = [];
        foreach (Sites::getAllSiteIds() as $siteId) {
            $siteSettings[$siteId] = new CategoryGroup_SiteSettings(['siteId' => $siteId, 'hasUrls' => true, 'uriFormat' => 'categories/{slug}', 'template' => '']);
        }
        $group->setSiteSettings($siteSettings);
        $group->setFieldLayout(layoutFor(Category::class, [$related, $relations]));
        $categories->saveGroup($group) or fail($group);
    }
    $category = fn(string $title): Category => Category::find()->group('testCategories')->title($title)->status(null)->one()
        ?? new Category(['groupId' => $group->id, 'title' => $title]);
    $source = $category('Test Category');
    $source->setFieldValue('relatedEntries', $target);
    Elements::saveElement($source) or fail($source);
    $targetCategory = $category('Target Category');
    if (!$targetCategory->id) {
        Elements::saveElement($targetCategory) or fail($targetCategory);
    }

    // Tag group
    $tags = Craft::$app->getTags();
    if (!$tagGroup = $tags->getTagGroupByHandle('testTags')) {
        $tagGroup = new TagGroup(['name' => 'Test Tags', 'handle' => 'testTags']);
        $tagGroup->setFieldLayout(layoutFor(Tag::class, [$related, $relations]));
        $tags->saveTagGroup($tagGroup) or fail($tagGroup);
    }
    $tag = fn(string $title): Tag => Tag::find()->group('testTags')->title($title)->status(null)->one()
        ?? new Tag(['groupId' => $tagGroup->id, 'title' => $title]);
    $sourceTag = $tag('Test Tag');
    $sourceTag->setFieldValue('relatedEntries', $target);
    Elements::saveElement($sourceTag) or fail($sourceTag);
    $targetTag = $tag('Target Tag');
    if (!$targetTag->id) {
        Elements::saveElement($targetTag) or fail($targetTag);
    }

    // Categories, Tags and Link fields on Pages, using the targets
    $categoriesField = ensureField(craft\fields\Categories::class, 'categories', 'Categories', ['sources' => ["group:$group->uid"]]);
    $tagsField = ensureField(craft\fields\Tags::class, 'tags', 'Tags', ['source' => "taggroup:$tagGroup->uid"]);
    $linkField = ensureField(Link::class, 'categoryLink', 'Category Link', ['types' => ['url', 'category']]);
    foreach ([$categoriesField, $tagsField, $linkField] as $field) {
        addFieldToLayout($field, $ctx['entryType']);
    }
    ensureEntry('Page via Categories field', ['categories' => [$targetCategory->id]]);
    ensureEntry('Page via Tags field', ['tags' => [$targetTag->id]]);
    ensureEntry('Page via Category link', ['categoryLink' => ['type' => 'category', 'value' => $targetCategory->id]]);
};
