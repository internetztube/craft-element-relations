<?php

declare(strict_types=1);

/**
 * Categories, tags and global sets, which Craft 6 has only through its Yii2 adapter (see dev/seed/adapter.php): as
 * relating elements and as targets, in the usage list (types, Where column, stock sort options and filter rules,
 * group params, permissions), in RelationsModel's statuses, GraphQL, replace and rollback, and the plugin's element
 * actions and filter. Without the adapter it does nothing.
 */

use craft\elements\Category;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Gql\Data\GqlSchema;
use CraftCms\Cms\Gql\Gql;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Str;
use CraftCms\Cms\User\Elements\User;
use CraftCms\Cms\User\UserPermissions;
use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Elements\Actions\ReplaceRelations;
use Internetztube\ElementRelations\Elements\Conditions\UsedConditionRule;
use Internetztube\ElementRelations\Elements\Queries\UsageQuery;
use Internetztube\ElementRelations\Elements\Usage;
use Internetztube\ElementRelations\Extractors\InterfaceElementReplacer;
use Internetztube\ElementRelations\Models\RelationsModel;
use Internetztube\ElementRelations\Models\Replacement;
use Internetztube\ElementRelations\Services\ExtractorService;
use Internetztube\ElementRelations\Services\ReplaceService;
use Internetztube\ElementRelations\Support\ActingUser;
use Internetztube\ElementRelations\Support\ElementSummary;

use function CraftCms\Cms\renderString;
use function CraftCms\Cms\t;

return function (array $ctx): void {
    if (!class_exists(Category::class)) {
        echo "  skipped: craftcms/yii2-adapter is not installed\n";
        return;
    }
    foreach ([...Category::find()->title('RT *')->status(null)->all(), ...Tag::find()->title('RT *')->status(null)->all(), ...User::find()->username('adapter-editor')->status(null)->all()] as $leftover) {
        Elements::deleteElement($leftover, true); // of an aborted run
    }
    $c = $ctx['target'];
    $footer = GlobalSet::find()->handle('footer')->one() ?? throw new RuntimeException('Run the seed with the adapter first');
    $sourceCategory = Category::find()->title('Test Category')->one() ?? throw new RuntimeException('Test Category');
    $sourceTag = Tag::find()->title('Test Tag')->one() ?? throw new RuntimeException('Test Tag');
    $targetCategory = Category::find()->title('Target Category')->one() ?? throw new RuntimeException('Target Category');
    $targetTag = Tag::find()->title('Target Tag')->one() ?? throw new RuntimeException('Target Tag');
    $categoryGroup = $sourceCategory->getGroup();
    $tagGroup = $sourceTag->getGroup();

    check(!array_diff(Usage::ADAPTER_TYPES, ElementRelations::elementTypes()), 'the adapter registers categories, tags and global sets as element types');

    // as relating elements and as targets
    checkRelation($c, 'Footer', 'global set');
    checkRelation($c, 'Test Category', 'category');
    checkRelation($c, 'Test Tag', 'tag');
    checkRelation($targetCategory, 'Page via Categories field', 'Categories field');
    checkRelation($targetCategory, 'Page via Category link', 'Link field, Category type');
    checkRelation($targetTag, 'Page via Tags field', 'Tags field');
    check(Category::find()->usesElement($c)->status(null)->ids() === [(int)$sourceCategory->id] && Tag::find()->usesElement($c)->ids() === [(int)$sourceTag->id] && GlobalSet::find()->usesElement($c)->ids() === [(int)$footer->id], 'usesElement on the adapter\'s queries: the category, tag and global set using Page C');
    $unusedCategories = Category::find()->unused()->status(null)->ids();
    $unusedTags = Tag::find()->unused()->ids();
    check(in_array((int)$sourceCategory->id, $unusedCategories, true) && !in_array((int)$targetCategory->id, $unusedCategories, true), 'unused() works for categories (Test Category is a source, not a target; Target Category is used)');
    check(in_array((int)$sourceTag->id, $unusedTags, true) && !in_array((int)$targetTag->id, $unusedTags, true), 'unused() works for tags');
    $twig = renderString('{{ craft.categories().usesElement(c).ids()|join(",") }}|{{ target.relations.count }}', ['c' => $c, 'target' => $targetCategory]);
    check($twig === "$sourceCategory->id|2", "Twig: craft.categories().usesElement(), and the field's value on a category ($twig)");

    // the field on a category and a tag
    $field = CraftCms\Cms\Support\Facades\Fields::getFieldByHandle('relations') ?? throw new RuntimeException('relations');
    $preview = $field->getPreviewHtml($targetCategory->getFieldValue('relations'), $targetCategory);
    check(str_contains($preview, 'Page via Categories field') && str_contains($preview, 'Page via Category link'), 'the field on a category previews its usages');
    check(str_contains($field->getPreviewHtml($sourceTag->getFieldValue('relations'), $sourceTag), t('field-value-unused', category: 'element-relations')) && $targetTag->getFieldValue('relations')->getCount() === 1, 'the field on a tag: Test Tag is unused, Target Tag used once');
    $input = withWebRequest(fn() => CraftCms\Cms\Support\Facades\HtmlStack::capture(fn(): string => $field->inputHtmlFor($targetCategory->getFieldValue('relations'), $targetCategory)));
    $decoded = html_entity_decode($input->html . $input->headHtml . $input->bodyHtml);
    check(str_contains($input->html, 'element-index') && str_contains($decoded, 'Craft.createElementIndex') && preg_match('/usesElement\W+' . $targetCategory->id . '\b/', $decoded) === 1, "the field renders Craft's element index of the category's usages on its edit page");

    // the usage list: one query across the types, the adapter's included
    $model = new RelationsModel((int)$c->id, (int)$c->siteId);
    $counts = $model->getCountsByType();
    foreach (Usage::ADAPTER_TYPES as $type) {
        $unified = (int)Usage::find()->usesElement($c)->elementTypes($type)->status(null)->drafts(null)->site('*')->count();
        $own = (int)$type::find()->usesElement($c)->status(null)->drafts(null)->site('*')->count();
        $elements = (int)$type::find()->usesElement($c)->status(null)->drafts(null)->site('*')->unique()->count();
        check($unified === $own && $elements === ($counts[$type] ?? 0) && $elements >= 1, "elementTypes($type) → $unified (own query: $own); the type count counts the element once: " . ($counts[$type] ?? 0));
    }
    check(ElementSummary::where($sourceCategory) === 'Test Categories' && ElementSummary::where($sourceTag) === 'Test Tags' && ElementSummary::where($footer) === '', 'Where column: the category and tag group, nothing for a global set');

    // statuses: each type takes the words it knows; a tag has none and only ever counts as enabled
    $notEntries = fn(array $elements): array => array_filter($elements, fn(ElementInterface $e): bool => !$e instanceof Entry);
    check($notEntries($model->getElements(sections: ['pages'], status: null)) === [] && $notEntries($model->getElements(entryTypes: ['page'], status: null)) === [] && $model->getCount(sections: ['pages'], status: null) < $model->getCount(status: null), 'sections / entryTypes: only entries (the category, tag and global set using Page C are not counted as “pages”)');
    check(array_filter($model->getElements(status: ['disabled']), fn(ElementInterface $e): bool => !$e::hasStatuses()) === [] && array_filter($model->getElements(status: ['enabled']), fn(ElementInterface $e): bool => $e instanceof Tag) !== [], 'status: a type without statuses (tag) only matches “enabled”, never “disabled”');
    $mixed = $model->getElements(status: ['live', 'enabled'], drafts: false);
    $has = fn(string $class): bool => array_filter($mixed, fn(ElementInterface $e): bool => is_a($e, $class)) !== [];
    check($has(Category::class) && $has(Tag::class) && $has(GlobalSet::class), 'status [“live”, “enabled”]: each type takes the words it knows (categories enabled, the tag and the global set too), none is dropped');

    // Craft's own sort options and filter rules of categories and tags
    $q = fn(): UsageQuery => Usage::find()->site('*')->status(null)->usesElement($c->id);
    Usage::$listedTypes = [Category::class, Tag::class];
    $attributes = [];
    foreach (Usage::sortOptions() as $key => $option) {
        $option = is_string($option) ? ['label' => $option, 'orderBy' => $key, 'attribute' => $key] : $option;
        $orderBy = $option['orderBy'];
        $order = $orderBy instanceof Closure ? $orderBy(SORT_DESC) : $orderBy;
        $sorted = $q()->elementTypes(Usage::ADAPTER_TYPES);
        $order instanceof Illuminate\Contracts\Database\Query\Expression ? $sorted->getQuery()->orderByRaw($order->getValue(Illuminate\Support\Facades\DB::getQueryGrammar())) : $sorted->orderBy((string)$order, 'desc');
        check(count($sorted->ids()) === (int)$q()->elementTypes(Usage::ADAPTER_TYPES)->count(), "sort by {$option['label']} works");
        $attributes[] = $option['attribute'] ?? $key;
    }
    check(in_array('group', $attributes, true), "Craft's own sort options of categories and tags are offered, the group's included (" . implode(', ', $attributes) . ')');
    $ruleClasses = array_map(fn($rule) => $rule::class, Usage::createCondition()->getSelectableConditionRules());
    check(in_array(craft\elements\conditions\categories\GroupConditionRule::class, $ruleClasses, true) && in_array(craft\elements\conditions\tags\GroupConditionRule::class, $ruleClasses, true), 'the filter offers the Group rules of categories and tags');
    Usage::$listedTypes = [Entry::class];
    $ruleClasses = array_map(fn($rule) => $rule::class, Usage::createCondition()->getSelectableConditionRules());
    check(!in_array(craft\elements\conditions\categories\GroupConditionRule::class, $ruleClasses, true), 'a list of entries offers no category Group rule');
    Usage::$listedTypes = null;
    // category and tag groups are numbered separately: Craft's group rules filter their own type only
    $byRule = function (string $rule, string $uid) use ($q): int {
        $condition = Usage::createCondition();
        $condition->addConditionRule($condition->createConditionRule(['class' => $rule, 'values' => [$uid]]));
        $query = $q();
        $condition->modifyQuery($query);
        return (int)$query->count();
    };
    $ownCategories = (int)Category::find()->site('*')->status(null)->usesElement($c->id)->groupId($categoryGroup->id)->count();
    $ownTags = (int)Tag::find()->site('*')->status(null)->usesElement($c->id)->groupId($tagGroup->id)->count();
    check($ownCategories > 0 && $ownTags > 0
        && $byRule(craft\elements\conditions\tags\GroupConditionRule::class, (string)$tagGroup->uid) === $ownTags
        && $byRule(craft\elements\conditions\categories\GroupConditionRule::class, (string)$categoryGroup->uid) === $ownCategories
        && (int)$q()->tagGroupId($categoryGroup->id)->elementTypes(Category::class)->count() === 0
        && (int)$q()->categoryGroupId($categoryGroup->id)->count() === $ownCategories
        && (int)$q()->tagGroupId($tagGroup->id)->count() === $ownTags
        && (int)$q()->groupId([$categoryGroup->id, $tagGroup->id])->count() === $ownCategories + $ownTags
        && (int)$q()->groupId($tagGroup->id)->elementTypes(Entry::class)->count() === 0, 'the Group rules of tags and categories each match their own type, as Craft counts them, even where the group ids overlap (' . $categoryGroup->id . ', ' . $tagGroup->id . '); groupId matches no entries');

    // permissions: a restricted editor sees a category with its group's permission, the global set with its own
    Illuminate\Support\Facades\Context::addHidden(CraftCms\Cms\Edition::class, CraftCms\Cms\Edition::Pro); // Solo has one user
    $editor = new User(['username' => 'adapter-editor', 'email' => 'adapter-editor@example.com']);
    Elements::saveElement($editor) or fail($editor);
    $section = Sections::getSectionByHandle('pages') ?? throw new RuntimeException('pages');
    $base = ['accessCp', "viewEntries:$section->uid", "viewPeerEntries:$section->uid", ...Sites::getAllSites(true)->map(fn($site): string => "editSite:$site->uid")->all()];
    $visible = function (array $permissions) use ($editor, $c): array {
        app(UserPermissions::class)->saveUserPermissions((int)$editor->id, $permissions);
        $user = User::find()->id($editor->id)->status(null)->one() ?? throw new RuntimeException('editor');
        return array_map('strval', Usage::find()->allSites()->status(null)->drafts(null)->viewableBy($user)->usesElement($c->id)->elementTypes(Usage::ADAPTER_TYPES)->all());
    };
    $without = $visible($base);
    $with = $visible([...$base, "viewCategories:$categoryGroup->uid", "editGlobalSet:$footer->uid"]);
    sort($with);
    check($without === ['Test Tag'] && $with === ['Footer', 'Test Category', 'Test Tag'], 'an editor sees the category only with its group\'s view permission, the global set only with its edit permission, tags always (' . json_encode([$without, $with]) . ')');
    Elements::deleteElement($editor, true);
    Illuminate\Support\Facades\Context::forgetHidden(CraftCms\Cms\Edition::class);

    // GraphQL: the adapter's types through their own resolvers, only with the schema's scopes
    $schema = fn(array $scope): GqlSchema => new GqlSchema(['uid' => Str::uuid()->toString(), 'name' => 'Adapter', 'scope' => [
        'sections.' . $section->uid . ':read',
        ...array_map(fn($type): string => "entrytypes.$type->uid:read", $section->getEntryTypes()),
        ...Sites::getAllSites(true)->map(fn($site): string => "sites.$site->uid:read")->all(),
        ...$scope,
    ]]);
    $gql = function (GqlSchema $schema) use ($c): array {
        $result = app(Gql::class)->executeQuery($schema, '{ entry(id: ' . $c->id . ') { ... on page_Entry { relations { count elements(limit: 100) { title } } } } }');
        $relations = $result['data']['entry']['relations'] ?? throw new RuntimeException(json_encode($result['errors'] ?? null) ?: 'no data');
        return [$relations['count'], array_column($relations['elements'], 'title')];
    };
    [$count, $titles] = $gql($schema([]));
    check(!array_intersect(['Footer', 'Test Category', 'Test Tag'], $titles) && $count === count($titles), 'GraphQL without their scopes: no category, tag or global set (' . $count . ' entries)');
    // with their scopes, the adapter (6.0.0-alpha.20) cannot build a schema (it passes its category queries to
    // array_push() with string keys), so the field's resolvers run directly under that schema
    // its global set resolver reads the `globalsets` row's uid, which the adapter (alpha.20) does not keep equal to the element's
    $footerUid = Illuminate\Support\Facades\DB::table('globalsets')->where('id', $footer->id)->value('uid');
    app(Gql::class)->setActiveSchema($schema(["categorygroups.$categoryGroup->uid:read", "taggroups.$tagGroup->uid:read", "globalsets.$footerUid:read"]));
    $fields = Internetztube\ElementRelations\Gql\Types\Relations::getFieldDefinitions();
    $titles = array_map('strval', $fields['elements']['resolve']($model, []));
    $count = $fields['count']['resolve']($model, []);
    app(Gql::class)->setActiveSchema(null);
    check(!array_diff(['Footer', 'Test Category', 'Test Tag'], $titles) && $count === count($titles), 'GraphQL with them: the category, tag and global set, counted (' . implode(', ', $titles) . ')');

    // element actions and filters on the adapter's own indexes
    $actions = fn(string $type): array => array_map(fn($action): string => is_array($action) ? (string)($action['type'] ?? '') : (is_string($action) ? $action : $action::class), $type::actions('*'));
    check(in_array(ReplaceRelations::class, $actions(Category::class), true) && in_array(ReplaceRelations::class, $actions(Tag::class), true), '“Replace everywhere…” on the category and tag indexes');
    check(in_array(UsedConditionRule::class, array_map(fn($rule) => $rule::class, Category::createCondition()->getSelectableConditionRules()), true), 'the “Used anywhere” filter on the category index');

    // replace and rollback: in the category, tag and global set, and of a category or tag in the fields using them
    // a global set exists in every site with one (non-translatable) value: rows per site, one shared reference
    $ids = fn(ElementInterface $element, string $handle): array => array_map('intval', $element->getFieldValue($handle)->ids());
    replaceRoundTrip($footer, $c, 'Global set, all sites', fn($g, $t) => $ids($g, 'relatedEntries') === [$t->id]);
    replaceRoundTrip($sourceCategory, $c, 'Category', fn($e, $t) => $ids($e, 'relatedEntries') === [$t->id]);
    replaceRoundTrip($sourceTag, $c, 'Tag', fn($e, $t) => $ids($e, 'relatedEntries') === [$t->id]);
    $page = fn(string $title): Entry => Entry::find()->title($title)->one() ?? throw new RuntimeException($title);
    replaceRoundTrip($page('Page via Categories field'), $targetCategory, 'Categories field', fn($e, $t) => $ids($e, 'categories') === [$t->id]);
    replaceRoundTrip($page('Page via Tags field'), $targetTag, 'Tags field', fn($e, $t) => $ids($e, 'tags') === [$t->id]);
    replaceRoundTrip($page('Page via Category link'), $targetCategory, 'Link field, Category type', fn($e, $t) => (int)$e->getFieldValue('categoryLink')?->getElement()?->id === (int)$t->id);

    // categories have no revisions: counted and recorded as such, and "revert to revisions" swaps them back
    $rt = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => 'RT category revisions']);
    Elements::saveElement($rt) or fail($rt);
    check(ReplaceService::revisionSupport($sourceCategory) === ReplaceService::REVISIONS_UNSUPPORTED, 'categories: no revisions');
    $result = ActingUser::run(admin(), fn(): array => ReplaceService::replace($c, $rt, [(int)$sourceCategory->id]));
    flushQueue();
    check($result['replaced'] === 1 && $result['withoutRevision'] === 1 && in_array('Test Category', relatingTitles($rt), true), 'the category is replaced, counted as changed without revision (' . json_encode($result) . ')');
    $operation = Replacement::query()->find($result['operationId']) ?? throw new RuntimeException('operation');
    $owners = array_column($operation->getRevisions(), null, 'ownerId');
    check(($owners[$sourceCategory->id]['support'] ?? null) === ReplaceService::REVISIONS_UNSUPPORTED && empty($owners[$sourceCategory->id]['revisionBeforeId']), 'the category is recorded as not supporting revisions');
    $back = ActingUser::run(admin(), fn(): array => ReplaceService::rollback($operation, ReplaceService::ROLLBACK_REVISIONS));
    flushQueue();
    check(in_array('Test Category', relatingTitles($c), true) && !in_array('Test Category', relatingTitles($rt), true) && $back['reverted'] === 0, 'the category (no revisions) is swapped back instead (' . json_encode($back) . ')');
    $operation->delete();
    Elements::deleteElement($rt, true);

    // the element replacers pass on a category: nothing outside its fields is read or replaced
    $claimed = [];
    foreach (ExtractorService::elementExtractors() as $extractor) {
        if (is_subclass_of($extractor, InterfaceElementReplacer::class) && $extractor::replace($sourceCategory, $c, $targetCategory) !== null) {
            $claimed[] = $extractor;
        }
    }
    check(!$claimed && !ReplaceService::replaceInElement($sourceCategory, $c, $targetCategory), 'every element replacer passes on a category (' . implode(', ', $claimed) . ')');
};
