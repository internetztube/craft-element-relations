<?php
/**
 * GraphQL never exposes more relating elements than the active schema may read: types/sections/volumes/sites outside
 * the schema, plugin element types, disabled elements (unless asked for) and drafts stay hidden, and `count` /
 * `isInUse` agree with `elements`. Creates temporary entries ("GS ...") relating to Page C and removes them again.
 */

use craft\elements\Entry;
use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use internetztube\elementRelations\gql\types\Relations;
use internetztube\elementRelations\models\RelationsModel;

return function (array $ctx): void {
    $elements = Craft::$app->getElements();
    $section = Craft::$app->getEntries()->getSectionByHandle('pages');
    $typeId = $section->getEntryTypes()[0]->id;
    $adminId = craft\elements\User::find()->admin()->one()->id;
    $asset = $ctx['asset'];
    $target = $ctx['target'];
    $volume = Craft::$app->getVolumes()->getVolumeByHandle('test');
    // every schema below may read non-enabled elements (the `status` argument needs it), only case 5 may not
    $siteScopes = ['elements.inactive:read', ...array_map(fn($site) => "sites.$site->uid:read", Craft::$app->getSites()->getAllSites(true))];
    $pagesScopes = ["sections.$section->uid:read", ...array_map(fn($t) => "entrytypes.$t->uid:read", $section->getEntryTypes())];

    $flush = function (): void {
        Craft::$app->trigger(yii\base\Application::EVENT_AFTER_REQUEST);
        Craft::$app->getQueue()->run();
    };
    $cleanup = function () use ($elements, $flush): void {
        foreach (Entry::find()->title('GS *')->status(null)->drafts(null)->provisionalDrafts(null)->site('*')->unique()->all() as $old) {
            $elements->deleteElement($old, true);
        }
        $flush();
    };
    // a unique uid per schema: Craft caches GraphQL results per schema uid
    $schema = fn(array $scope) => new GqlSchema(['uid' => StringHelper::UUID(), 'scope' => $scope]);
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
    foreach (Craft::$app->getGql()->getAllSchemaComponents()['queries'] as $group) {
        $flatten($group);
    }
    $full = $schema($allScopes);
    // Craft builds the GraphQL types once per process for one schema (as in a request) and cannot rebuild them for
    // another one, so every query runs in its own process, like a request with that schema
    $execute = function (GqlSchema $schema, string $query, array $variables = []): array {
        $code = 'require $argv[1] . "/bootstrap.php"; require CRAFT_VENDOR_PATH . "/craftcms/cms/bootstrap/console.php";'
            . '$in = json_decode(stream_get_contents(STDIN), true);'
            . 'echo json_encode(Craft::$app->getGql()->executeQuery(new craft\\models\\GqlSchema($in[0]), $in[1], $in[2]));';
        $process = proc_open([PHP_BINARY, '-r', $code, CRAFT_BASE_PATH], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        fwrite($pipes[0], json_encode([['uid' => $schema->uid, 'scope' => $schema->scope], $query, $variables ?: null]));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        proc_close($process);
        return json_decode($output, true) ?? ['errors' => [$output . $errors]];
    };
    $run = function (GqlSchema $schema, string $query, array $variables = []) use ($execute): array {
        $result = $execute($schema, $query, $variables);
        if (!empty($result['errors'])) {
            check(false, 'GraphQL query failed: ' . json_encode($result['errors']));
        }
        return $result['data'];
    };
    $assetRelations = fn(GqlSchema $s) => $run($s, 'query($id: [QueryArgument]) { asset(id: $id) { ... on test_Asset { relations ' . $fields . ' } } }', ['id' => $asset->id])['asset']['relations'] ?? null;
    $entryRelations = fn(GqlSchema $s) => $run($s, 'query($id: [QueryArgument]) { entry(id: $id) { ... on page_Entry { relations ' . $fields . ' } } }', ['id' => $target->id])['entry']['relations'] ?? null;
    $ids = fn(array $list) => collect($list)->pluck('id')->map(fn($id) => (int)$id)->sort()->values()->all();
    $consistent = function (array $r, string $what) use ($ids): void {
        check($r['count'] === count($r['elements']) && $r['allSitesCount'] === count($r['allSites']) && $r['disabledCount'] === count($r['disabled']), "$what: count matches elements (default, all sites, disabled)");
        check($r['isInUse'] === ($r['count'] > 0), "$what: isInUse agrees with count (only what the schema may read, no plugin settings)");
        check(count($ids($r['elements'])) === count(array_unique($ids($r['elements']))), "$what: no element twice");
    };
    $unscopedTypes = fn($element) => (new RelationsModel($element->id, $element->siteId))->getElementTypes();

    // the content makes the test meaningful: the asset is used by entries and a user, Page C by many types
    $assetTypes = $unscopedTypes($asset);
    check(in_array(Entry::class, $assetTypes, true) && in_array(craft\elements\User::class, $assetTypes, true), 'unscoped: the asset is used by entries and the admin user (' . implode(', ', $assetTypes) . ')');
    $pluginTypes = array_values(array_diff($unscopedTypes($target), array_keys(Relations::RESOLVERS)));
    echo '  (Page C is also used by types without a GraphQL resolver mapping: ' . (implode(', ', $pluginTypes) ?: 'none') . ")\n";

    $cleanup();
    $make = function (string $title, array $attrs = []) use ($section, $typeId, $elements, $target): Entry {
        $e = new Entry(['sectionId' => $section->id, 'typeId' => $typeId, 'title' => $title] + $attrs);
        $e->setFieldValue('relatedEntries', [$target->id]);
        $elements->saveElement($e) or throw new RuntimeException(json_encode($e->getErrors()));
        return $e;
    };
    $disabled = $make('GS disabled', ['enabled' => false]);
    $live = $make('GS live');
    $draft = Craft::$app->getDrafts()->createDraft($live, $adminId, 'GS draft');
    $unpublished = new Entry(['sectionId' => $section->id, 'typeId' => $typeId, 'title' => 'GS unpublished']);
    $unpublished->setFieldValue('relatedEntries', [$target->id]);
    Craft::$app->getDrafts()->saveElementAsDraft($unpublished, $adminId, null, null, false);
    $flush();
    $draftIds = [$draft->id, $unpublished->id];
    check(count(array_intersect($draftIds, array_map(fn($e) => $e->id, (new RelationsModel($target->id, $target->siteId))->getElements([])))) === 2, 'unscoped RelationsModel (Twig/CP) still includes the drafts');

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
    $expected = Entry::find()->usesElement($asset)->section('pages')->siteId($asset->siteId)->ids();
    sort($expected);
    check($expected !== [] && $ids($r['elements']) === array_map('intval', $expected), 'volume + pages schema: exactly the live "pages" entries relating to the asset (' . count($expected) . ')');
    check(collect($r['elements'])->every(fn($e) => str_ends_with($e['__typename'], '_Entry')), 'volume + pages schema: no user (the admin photo) or plugin elements');
    $consistent($r, 'volume + pages schema, asset');

    // ... and on Page C: disabled only on request, drafts never, no category/tag/global set/plugin elements
    $r = $entryRelations($pagesSchema);
    $all = [...$ids($r['elements']), ...$ids($r['allSites']), ...$ids($r['disabled'])];
    check(in_array($live->id, $ids($r['elements']), true), 'pages schema: GS live is listed');
    check(!in_array($disabled->id, $ids($r['elements']), true) && !in_array($disabled->id, $ids($r['allSites']), true), 'pages schema: the disabled entry is hidden by default');
    check(in_array($disabled->id, $ids($r['disabled']), true) && !in_array($live->id, $ids($r['disabled']), true), 'pages schema: status ["disabled"] lists the disabled entry (and not the live one)');
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
            if (!isset(Relations::RESOLVERS[$type]) || !class_exists(Relations::RESOLVERS[$type][0])) {
                continue;
            }
            $query = fn() => $type::find()->usesElement($element)->drafts(false)->provisionalDrafts(false)->revisions(false);
            $filter = fn(array $ids) => $ids;
            if ($type === craft\elements\User::class) {
                // users the full schema may read, according to Craft's own `users` query (none on Craft Solo: no user groups)
                $filter = fn(array $ids) => $ids ? array_column($run($full, 'query($id: [QueryArgument]) { users(id: $id) { id } }', ['id' => $ids])['users'], 'id') : [];
            }
            $expected = [...$expected, ...$filter($query()->siteId($element->siteId)->ids())];
            $expectedAllSites = [...$expectedAllSites, ...$filter($query()->site('*')->ids())];
        }
        $expected = array_map('intval', $expected);
        $expectedAllSites = array_map('intval', $expectedAllSites);
        sort($expected);
        sort($expectedAllSites);
        check($ids($r['elements']) === $expected, "full schema, $what: same elements as the per-type queries (" . implode(', ', array_unique(array_column($r['elements'], '__typename'))) . ')');
        check($ids($r['allSites']) === $expectedAllSites, "full schema, $what: siteIds: [] = all sites (" . count($expectedAllSites) . ')');
        check(array_intersect($draftIds, $ids($r['allSites'])) === [] && !in_array($disabled->id, $ids($r['allSites']), true), "full schema, $what: no drafts, no disabled entry");
        $unmapped = array_values(array_diff($unscopedTypes($element), array_keys(Relations::RESOLVERS)));
        $unmappedIds = $unmapped ? array_map(fn($e) => (int)$e->id, (new RelationsModel($element->id, $element->siteId))->getElements([], types: $unmapped)) : [];
        check(array_intersect($unmappedIds, $ids($r['allSites'])) === [], "full schema, $what: none of the " . count($unmappedIds) . ' elements of types without a resolver (' . (implode(', ', $unmapped) ?: 'none installed') . ')');
        $consistent($r, "full schema, $what");
    }
    $typenames = array_unique(array_column($entryRelations($full)['elements'], '__typename'));
    check(count($typenames) > 1, 'full schema: Page C is used by several mapped types (' . implode(', ', $typenames) . ')');

    // 5. a schema without "Query for non-enabled elements": defaults work, `status` is refused (Craft does not offer it either)
    $noInactive = $schema(array_values(array_diff([...$pagesScopes, ...$siteScopes], ['elements.inactive:read'])));
    $query = 'query($id: [QueryArgument]) { entry(id: $id) { ... on page_Entry { relations { count elements { id } } } } }';
    $r = $run($noInactive, $query, ['id' => $target->id])['entry']['relations'];
    check($r['count'] === count($r['elements']) && in_array($live->id, $ids($r['elements']), true) && !in_array($disabled->id, $ids($r['elements']), true), 'schema without inactive scope: live entries, count matches');
    foreach (['elements(status: ["disabled"]) { id }', 'count(status: ["disabled"])', 'isInUse(status: ["disabled"])'] as $field) {
        $result = $execute($noInactive, 'query($id: [QueryArgument]) { entry(id: $id) { ... on page_Entry { relations { ' . $field . ' } } } }', ['id' => $target->id]);
        check(str_contains(json_encode($result['errors'] ?? []), 'non-enabled elements'), "schema without inactive scope: $field is refused");
    }

    $cleanup();
};
