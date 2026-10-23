<?php

declare(strict_types=1);

/**
 * GraphQL never exposes more relating elements than the active schema may read: types/sections/volumes/sites outside
 * the schema, element types without a resolver, disabled elements (unless asked for) and drafts stay hidden, and
 * `count` / `isInUse` agree with `elements`. Creates temporary entries ("GS ...") relating to Page C and the asset and
 * removes them again.
 */

use CraftCms\Cms\Cms;
use CraftCms\Cms\Edition;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Gql\Data\GqlSchema;
use CraftCms\Cms\Gql\Gql;
use CraftCms\Cms\Support\Facades\Drafts;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\Volumes;
use CraftCms\Cms\Support\Str;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Facades\Context;
use Internetztube\ElementRelations\Gql\Types\Relations;
use Internetztube\ElementRelations\Models\RelationsModel;

return function (array $ctx): void {
    // Craft Solo has no user groups, so GraphQL reads no users: Pro in memory, so the admin (whose photo the asset is)
    // is a second type among the asset's usages
    $editionBefore = Context::getHidden(Edition::class);
    Context::addHidden(Edition::class, Edition::Pro);
    $section = Sections::getSectionByHandle('pages') ?? throw new RuntimeException('pages');
    $typeId = $section->getEntryTypes()[0]->id;
    $adminId = (int)admin()->id;
    $asset = $ctx['asset'];
    $target = $ctx['target'];
    $volume = Volumes::getVolumeByHandle('test') ?? throw new RuntimeException('volume');
    // every schema below may read non-enabled elements (the `status` argument needs it), only case 5 may not
    $siteScopes = ['elements.inactive:read', ...Sites::getAllSites(true)->map(fn($site): string => "sites.$site->uid:read")->all()];
    $pagesScopes = ["sections.$section->uid:read", ...array_map(fn($t): string => "entrytypes.$t->uid:read", $section->getEntryTypes())];

    $cleanup = function (): void {
        foreach (Entry::find()->title('GS *')->status(null)->drafts(null)->provisionalDrafts(null)->site('*')->unique()->all() as $old) {
            Elements::deleteElement($old, true);
        }
        flushQueue();
    };
    // a unique uid per schema: Craft caches GraphQL results per schema uid
    $schema = fn(array $scope): GqlSchema => new GqlSchema(['uid' => Str::uuid()->toString(), 'name' => 'GS', 'scope' => array_values($scope)]);
    $fields = '{ count isInUse isUsedInSeomaticGlobalSettings elements { id title __typename }
        allSites: elements(siteIds: []) { id }
        allSitesCount: count(siteIds: [])
        disabled: elements(status: ["disabled"]) { id title }
        disabledCount: count(status: ["disabled"]) }';
    // a schema with every query scope
    $allScopes = [];
    $flatten = function (array $components) use (&$flatten, &$allScopes): void {
        foreach ($components as $key => $component) {
            $allScopes[] = $key;
            $flatten($component['nested'] ?? []);
        }
    };
    foreach (app(Gql::class)->getAllSchemaComponents()['queries'] as $group) {
        $flatten($group);
    }
    $full = $schema($allScopes);
    // Craft 6 builds the GraphQL types again for each schema it is given (4.x ran each query in its own process)
    $execute = fn(GqlSchema $schema, string $query, array $variables = []): array => app(Gql::class)->executeQuery($schema, $query, $variables ?: null);
    $run = function (GqlSchema $schema, string $query, array $variables = []) use ($execute): array {
        $result = $execute($schema, $query, $variables);
        if (!empty($result['errors'])) {
            check(false, 'GraphQL query failed: ' . json_encode($result['errors']));
        }
        return $result['data'];
    };
    $assetRelations = fn(GqlSchema $s) => $run($s, 'query($id: [QueryArgument]) { asset(id: $id) { ... on test_Asset { relations ' . $fields . ' } } }', ['id' => $asset->id])['asset']['relations'] ?? null;
    $entryRelations = fn(GqlSchema $s) => $run($s, 'query($id: [QueryArgument]) { entry(id: $id) { ... on page_Entry { relations ' . $fields . ' } } }', ['id' => $target->id])['entry']['relations'] ?? null;
    $ids = fn(array $list): array => collect($list)->pluck('id')->map(fn($id): int => (int)$id)->sort()->values()->all();
    $consistent = function (array $r, string $what) use ($ids): void {
        check($r['count'] === count($r['elements']) && $r['allSitesCount'] === count($r['allSites']) && $r['disabledCount'] === count($r['disabled']), "$what: count matches elements (default, all sites, disabled)");
        check($r['isInUse'] === ($r['count'] > 0), "$what: isInUse agrees with count (only what the schema may read, no plugin settings)");
        check(count($ids($r['elements'])) === count(array_unique($ids($r['elements']))), "$what: no element twice");
    };
    $unscopedTypes = fn(ElementInterface $element): array => (new RelationsModel((int)$element->id, (int)$element->siteId))->getElementTypes();

    $cleanup();
    $make = function (string $title, array $attrs = [], ?ElementInterface $uses = null) use ($section, $typeId, $target): Entry {
        $e = new Entry(['sectionId' => $section->id, 'typeId' => $typeId, 'title' => $title] + $attrs);
        $uses ??= $target;
        $e->setFieldValue($uses instanceof Entry ? 'relatedEntries' : 'relatedAssets', [$uses->id]);
        Elements::saveElement($e) or fail($e);
        return $e;
    };
    // the asset's usages in 4.x came from many integrations; here one entry and the admin's photo
    $make('GS asset', [], $asset);
    $disabled = $make('GS disabled', ['enabled' => false]);
    $live = $make('GS live');
    $make('GS more'); // more than the maxGraphqlResults below (2)
    $draft = Drafts::createDraft($live, $adminId, 'GS draft');
    $unpublished = new Entry(['sectionId' => $section->id, 'typeId' => $typeId, 'title' => 'GS unpublished']);
    $unpublished->setFieldValue('relatedEntries', [$target->id]);
    Drafts::saveElementAsDraft($unpublished, $adminId, null, null, false);
    flushQueue();

    // the content makes the test meaningful: the asset is used by entries and a user, Page C by entries
    $assetTypes = $unscopedTypes($asset);
    check(in_array(Entry::class, $assetTypes, true) && in_array(User::class, $assetTypes, true), 'unscoped: the asset is used by entries and the admin user (' . implode(', ', $assetTypes) . ')');
    $unmappedTypes = array_values(array_diff($unscopedTypes($target), array_keys(Relations::RESOLVERS)));
    echo '  (Page C is also used by types without a GraphQL resolver mapping: ' . (implode(', ', $unmappedTypes) ?: 'none') . ")\n";
    $draftIds = [(int)$draft->id, (int)$unpublished->id];
    check(count(array_intersect($draftIds, array_map(fn(ElementInterface $e): int => (int)$e->id, (new RelationsModel((int)$target->id, (int)$target->siteId))->getElements([])))) === 2, 'unscoped RelationsModel (Twig/CP) still includes the drafts');

    // 1. volume only: the asset itself is readable, but nothing that relates to it is
    $r = $assetRelations($schema(["volumes.$volume->uid:read", ...$siteScopes]));
    check($r !== null, 'volume-only schema can read the asset');
    $types = array_unique(array_column($r['elements'], '__typename'));
    check(collect($types)->every(fn($t) => str_ends_with($t, '_Asset')), 'volume-only schema: no entries, users or plugin elements relate to the asset (got ' . (implode(', ', $types) ?: 'none') . ')');
    check($r['allSitesCount'] === 0 && $r['disabledCount'] === 0, 'volume-only schema: count 0 in all sites and for disabled elements');
    $consistent($r, 'volume-only schema');

    // 2. volume + "pages": live entries of "pages" relating to the asset, nothing else
    $pagesSchema = $schema(["volumes.$volume->uid:read", ...$pagesScopes, ...$siteScopes]);
    $r = $assetRelations($pagesSchema);
    $expected = array_map('intval', Entry::find()->usesElement($asset)->section('pages')->siteId($asset->siteId)->ids());
    sort($expected);
    check($expected !== [] && $ids($r['elements']) === $expected, 'volume + pages schema: exactly the live "pages" entries relating to the asset (' . count($expected) . ')');
    check(collect($r['elements'])->every(fn($e) => str_ends_with($e['__typename'], '_Entry')), 'volume + pages schema: no user (the admin photo) or plugin elements');
    $consistent($r, 'volume + pages schema, asset');

    // ... and on Page C: disabled only on request, drafts never, nothing but entries
    $r = $entryRelations($pagesSchema);
    $all = [...$ids($r['elements']), ...$ids($r['allSites']), ...$ids($r['disabled'])];
    check(in_array((int)$live->id, $ids($r['elements']), true), 'pages schema: GS live is listed');
    check(!in_array((int)$disabled->id, $ids($r['elements']), true) && !in_array((int)$disabled->id, $ids($r['allSites']), true), 'pages schema: the disabled entry is hidden by default');
    check(in_array((int)$disabled->id, $ids($r['disabled']), true) && !in_array((int)$live->id, $ids($r['disabled']), true), 'pages schema: status ["disabled"] lists the disabled entry (and not the live one)');
    check(array_intersect($draftIds, $all) === [], 'pages schema: drafts and unpublished drafts never appear');
    check(collect($r['elements'])->every(fn($e) => $e['__typename'] === 'page_Entry'), 'pages schema: only entries relate to Page C');
    $consistent($r, 'pages schema, Page C');

    // 3. no site in the schema: nothing at all, even with siteIds: []
    $r = $entryRelations($schema([...$pagesScopes, 'elements.inactive:read']));
    check($r === null || ($r['count'] === 0 && $r['allSitesCount'] === 0 && $r['disabled'] === []), 'schema without sites: no relating elements');

    // 4. everything: exactly the per-type queries of the mapped resolvers with their default status, no drafts
    foreach (['asset' => [$asset, $assetRelations], 'Page C' => [$target, $entryRelations]] as $what => [$element, $relations]) {
        $r = $relations($full);
        $expected = [];
        $expectedAllSites = [];
        foreach ($unscopedTypes($element) as $type) {
            if (!isset(Relations::RESOLVERS[$type])) {
                continue;
            }
            $query = fn() => $type::find()->usesElement($element)->drafts(false)->provisionalDrafts(false)->revisions(false);
            $filter = fn(array $ids): array => $ids;
            if ($type === User::class) {
                // users the full schema may read, according to Craft's own `users` query (none on Craft Solo: no user groups)
                $filter = fn(array $ids): array => $ids ? array_column($run($full, 'query($id: [QueryArgument]) { users(id: $id) { id } }', ['id' => $ids])['users'], 'id') : [];
            }
            $expected = [...$expected, ...$filter($query()->siteId($element->siteId)->ids())];
            $expectedAllSites = [...$expectedAllSites, ...$filter($query()->site('*')->unique()->ids())]; // each element once, like the field's list
        }
        $expected = array_map('intval', $expected);
        $expectedAllSites = array_map('intval', $expectedAllSites);
        sort($expected);
        sort($expectedAllSites);
        check($ids($r['elements']) === $expected, "full schema, $what: same elements as the per-type queries (" . implode(', ', array_unique(array_column($r['elements'], '__typename'))) . ')');
        check($ids($r['allSites']) === $expectedAllSites, "full schema, $what: siteIds: [] = all sites (" . count($expectedAllSites) . ')');
        check(array_intersect($draftIds, $ids($r['allSites'])) === [] && !in_array((int)$disabled->id, $ids($r['allSites']), true), "full schema, $what: no drafts, no disabled entry");
        $unmapped = array_values(array_diff($unscopedTypes($element), array_keys(Relations::RESOLVERS)));
        $unmappedIds = $unmapped ? array_map(fn(ElementInterface $e): int => (int)$e->id, (new RelationsModel((int)$element->id, (int)$element->siteId))->getElements([], types: $unmapped)) : [];
        check(array_intersect($unmappedIds, $ids($r['allSites'])) === [], "full schema, $what: none of the " . count($unmappedIds) . ' elements of types without a resolver (' . (implode(', ', $unmapped) ?: 'none installed') . ')');
        $consistent($r, "full schema, $what");
    }
    // (4.x: Page C, used by entries, categories, tags, global sets; on Craft 6 the asset is the one with several types)
    $typenames = array_unique(array_column($assetRelations($full)['elements'], '__typename'));
    check(count($typenames) > 1, 'full schema: the asset is used by several mapped types (' . implode(', ', $typenames) . ')');

    // 4c. Craft's own queries take `usesElement`: any query, any of Craft's arguments, Craft's own scoping
    $model = new RelationsModel((int)$target->id, (int)$target->siteId);
    $own = $run($full, 'query($id: [QueryArgument]) { entries(section: "pages", usesElement: $id, status: null) { id } allSites: entries(usesElement: $id, status: null, site: "*", unique: true) { id } none: entries(usesElement: 999999999) { id } }', ['id' => $target->id]);
    $twigEntries = $ids(array_map(fn(ElementInterface $e): array => ['id' => $e->id], $model->getElements([], sections: ['pages'], status: null, drafts: false)));
    check($ids($own['entries']) === $twigEntries && $twigEntries !== [], 'entries(section: "pages", usesElement: C): Craft’s own query lists the ' . count($twigEntries) . ' entries the Twig param does');
    check(count($own['allSites']) === count(array_unique($ids($own['allSites']))) && count($own['allSites']) >= count($own['entries']) && $own['none'] === [], 'entries(usesElement: C, site: "*", unique: true): each entry once; an element nothing uses: none');

    // 5. a schema without "Query for non-enabled elements": defaults work, `status` is refused (Craft does not offer it either)
    $noInactive = $schema(array_diff([...$pagesScopes, ...$siteScopes], ['elements.inactive:read']));
    $query = 'query($id: [QueryArgument]) { entry(id: $id) { ... on page_Entry { relations { count elements { id } } } } }';
    $r = $run($noInactive, $query, ['id' => $target->id])['entry']['relations'];
    check($r['count'] === count($r['elements']) && in_array((int)$live->id, $ids($r['elements']), true) && !in_array((int)$disabled->id, $ids($r['elements']), true), 'schema without inactive scope: live entries, count matches');
    foreach (['elements(status: ["disabled"]) { id }', 'count(status: ["disabled"])', 'isInUse(status: ["disabled"])'] as $field) {
        $result = $execute($noInactive, 'query($id: [QueryArgument]) { entry(id: $id) { ... on page_Entry { relations { ' . $field . ' } } } }', ['id' => $target->id]);
        check(str_contains(json_encode($result['errors'] ?? []) ?: '', 'non-enabled elements'), "schema without inactive scope: $field is refused");
    }

    // never more elements than Craft's maxGraphqlResults, asked for more or not at all
    $config = Cms::config();
    $maxBefore = $config->maxGraphqlResults;
    $config->maxGraphqlResults = 2;
    $capped = $run($full, 'query($id: [QueryArgument]) { entry(id: $id) { ... on page_Entry { relations { count more: elements(limit: 10) { id } all: elements { id } } } } }', ['id' => $target->id])['entry']['relations'] ?? [];
    $config->maxGraphqlResults = $maxBefore;
    check(count($capped['more'] ?? []) === 2 && count($capped['all'] ?? []) === 2 && ($capped['count'] ?? 0) > 2, 'elements never returns more than maxGraphqlResults (2), count still counts all (' . ($capped['count'] ?? '?') . ')');

    $cleanup();
    $editionBefore === null ? Context::forgetHidden(Edition::class) : Context::addHidden(Edition::class, $editionBefore);
};
