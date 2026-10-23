<?php

declare(strict_types=1);

/**
 * Smoke test against the seeded Craft 6 dev project (see seed.php). Exits non-zero on the first failed assertion.
 * Every `dev/smoke/<name>.php` returns a closure `function (array $ctx): void` with the assertions of one area.
 *
 * Run: dev/dev.sh smoke   (or: php dev/smoke.php <craft-project-dir>, after seeding and rebuilding the cache)
 */

require __DIR__ . '/craft.php';
require __DIR__ . '/lib.php';
$smokeStartedAt = now()->subSecond(); // the replacement history this run writes is removed again at the end
// a run that failed (exit) while switching the plugin edition left Craft's project config lock behind
Illuminate\Support\Facades\Cache::lock(CraftCms\Cms\ProjectConfig\ProjectConfig::MUTEX_NAME)->forceRelease();

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Asset\Folders;
use CraftCms\Cms\Asset\Validation\AssetRules;
use CraftCms\Cms\Edition;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Field;
use CraftCms\Cms\Gql\Data\GqlSchema;
use CraftCms\Cms\Gql\Gql;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\HtmlStack;
use CraftCms\Cms\Support\Facades\Plugins;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Str;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Elements\Usage;
use Internetztube\ElementRelations\Fields\ElementRelationsField;
use Internetztube\ElementRelations\Jobs\ResaveElementRelationsJob;
use Internetztube\ElementRelations\Models\CacheRecord;
use Internetztube\ElementRelations\Models\RelationsModel;
use Internetztube\ElementRelations\Models\Replacement;
use Internetztube\ElementRelations\Services\ExtractorService;
use Internetztube\ElementRelations\Services\QueueElementsForRefreshService;
use Internetztube\ElementRelations\Services\ReplaceService;
use Internetztube\ElementRelations\Support\ActingUser;
use Internetztube\ElementRelations\Support\ElementSummary;

use function CraftCms\Cms\renderString;

function check(bool $ok, string $what): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
    if (!$ok) {
        exit(1);
    }
}

/**
 * The PHP binary plus the coverage flags this process runs with (dev/dev.sh coverage), for subprocesses that should
 * count towards the coverage of src/ too: Artisan commands, a fresh process for GraphQL schema caches.
 * @return list<string>
 */
function phpCommand(): array
{
    $command = [PHP_BINARY];
    if (ini_get('pcov.enabled')) {
        foreach (['pcov.enabled', 'pcov.directory', 'auto_prepend_file', 'opcache.enable'] as $ini) {
            $command[] = '-d';
            $command[] = $ini . '=' . ini_get($ini);
        }
    }
    return $command;
}

/** The admin of the dev project. */
function admin(): User
{
    $admin = User::find()->username('admin')->one();
    return $admin instanceof User ? $admin : throw new RuntimeException('no admin');
}

/**
 * Runs `$fn` as a control panel request of `$user` (the admin by default): Craft's index HTML reads the request and
 * the current user's permissions (editable sites, ...); a console process has neither.
 */
function withWebRequest(callable $fn, ?User $user = null): mixed
{
    $before = app('request');
    $request = Request::create(rtrim((string)config('app.url'), '/') . '/admin', 'GET');
    $request->attributes->set('isCpRequest', true);
    app()->instance('request', $request);
    // the template hook Craft's control panel middleware registers for the index's sources (their sort options); not
    // its toolbar hook, which the 4.x smoke (Craft 5 registered both for web requests only) did not run either. The
    // hooks are a scoped service, which a queue run (flushQueue()) starts afresh.
    static $hooks = null;
    if ($hooks !== app(\CraftCms\Cms\View\TemplateHooks::class)) {
        $hooks = app(\CraftCms\Cms\View\TemplateHooks::class);
        $hooks->register('cp.elements.sources', \CraftCms\Cms\View\Hooks\PrepareElementSourcesVariables::class);
    }
    // and no console: the plugin checks app()->runningInConsole() like 4.x checked for a console request
    $console = new ReflectionProperty(app(), 'isRunningInConsole');
    $wasConsole = $console->getValue(app());
    $console->setValue(app(), false);
    try {
        return ActingUser::run($user ?? admin(), $fn);
    } finally {
        $console->setValue(app(), $wasConsole);
        app()->instance('request', $before);
    }
}

/**
 * Titles of the elements (all sites, all statuses) that relate to `$element`.
 * @return list<string>
 */
function relatingTitles(ElementInterface $element): array
{
    $titles = array_map(fn(ElementInterface $e): string => (string)$e, (new RelationsModel((int)$element->id, (int)$element->siteId))->getElements([]));
    sort($titles);
    return $titles;
}

/** Pushes the deferred refreshes (a script never terminates the app, where the plugin pushes them) and runs the queue. */
function flushQueue(): void
{
    QueueElementsForRefreshService::destruct();
    app(Kernel::class)->call('queue:work', ['--stop-when-empty' => true, '--tries' => 1, '--quiet' => true]);
}

/** Queued jobs of a class (Laravel's database queue). */
function queuedJobs(string $class): int
{
    return DB::table('jobs')->where('payload', 'like', '%' . str_replace('\\', '\\\\\\\\', $class) . '%')->count();
}

/** A new asset in the test volume, from a 1×1 PNG. */
function createAsset(string $filename, string $title = ''): Asset
{
    $volumeAsset = Asset::find()->volume('test')->orderBy('elements.id')->one() ?? throw new RuntimeException('Run seed.php first');
    $tmp = tempnam(sys_get_temp_dir(), 'er') . '.png';
    file_put_contents($tmp, base64_decode(PNG_1X1));
    $asset = Elements::createElement([
        'type' => Asset::class,
        'volumeId' => $volumeAsset->volumeId,
        'newFolderId' => app(Folders::class)->getRootFolderByVolumeId((int)$volumeAsset->volumeId)->id,
        'tempFilePath' => $tmp,
        'newFilename' => $filename,
        'title' => $title ?: null,
        'avoidFilenameConflicts' => true,
    ]);
    $asset->ruleset->useScenario(AssetRules::SCENARIO_CREATE);
    Elements::saveElement($asset) or fail($asset);
    return $asset;
}

/**
 * Round trip of the replace feature on one relating element, the way the field's wizard calls it: `$from` is swapped
 * for a temporary element of the same type in `$source` only, the relation must move, `$pointsTo($reloaded, $target)`
 * must see the new reference in the plugin's own field value; then the operation is rolled back and the original
 * checked again. Every extractor's replacer gets exercised through this helper by its integration smoke.
 */
function replaceRoundTrip(ElementInterface $source, ElementInterface $from, string $what, callable $pointsTo): void
{
    if ($from instanceof Entry) {
        $target = new Entry(['sectionId' => $from->sectionId, 'typeId' => $from->typeId, 'title' => "RT $what"]);
        Elements::saveElement($target) or fail($target);
    } elseif ($from instanceof Asset) {
        $target = createAsset('rt.png', "RT $what");
    } elseif (in_array($from::class, Usage::ADAPTER_TYPES, true) && isset($from->groupId)) {
        // a category or tag (Craft's Yii2 adapter): a new one in the same group
        $target = new ($from::class)(['groupId' => $from->groupId, 'title' => "RT $what"]);
        Elements::saveElement($target) or fail($target);
    } else {
        throw new RuntimeException('replaceRoundTrip supports entries, assets, categories and tags as "from"');
    }
    $reload = fn(): ?ElementInterface => $source::find()->id($source->id)->siteId($source->siteId)->status(null)->one();
    // an element it does not use (the new target): every replacer says there is nothing to change
    $copy = $reload() ?? throw new RuntimeException('source gone');
    $changed = [];
    foreach ($copy->getFieldLayout()?->getCustomFields() ?? [] as $field) {
        if ($field instanceof Field && ReplaceService::replaceInField($field, $copy, $target, $from)) {
            $changed[] = $field->handle;
        }
    }
    if (ReplaceService::replaceInElement($copy, $target, $from)) {
        $changed[] = 'the element itself';
    }
    check(!$changed, "$what: nothing to replace where the element does not use it (" . implode(', ', $changed) . ')');
    $result = ActingUser::run(admin(), fn(): array => ReplaceService::replace($from, $target, [(int)$source->id]));
    flushQueue();
    check($result['replaced'] === 1 && !$result['skipped'], "$what: replace rewrites the element, counted once whatever its sites (" . json_encode($result) . ')');
    check(in_array((string)$source, relatingTitles($target), true) && !in_array((string)$source, relatingTitles($from), true), "$what: the relation moved to the target");
    check((bool)$pointsTo($reload(), $target), "$what: the field value points to the target");

    // undo through the operation log, exactly what the wizard's Undo and the history's Roll back do
    $operation = Replacement::query()->find($result['operationId']);
    check($operation instanceof Replacement && $operation->status === Replacement::STATUS_APPLIED && $operation->changeList(), "$what: the operation is logged with its changes");
    $back = ActingUser::run(admin(), fn(): array => ReplaceService::rollback($operation));
    flushQueue();
    check($back['restored'] === 1 && !$back['skipped'] && in_array((string)$source, relatingTitles($from), true) && !in_array((string)$source, relatingTitles($target), true) && (bool)$pointsTo($reload(), $from), "$what: rollback restores the original (" . json_encode($back) . ')');
    check(Replacement::query()->find($operation->id)?->status === Replacement::STATUS_ROLLED_BACK, "$what: the operation is marked rolled back");
    $operation->delete(); // keep the dev project's history clean
    Elements::deleteElement($target, true);
}

/** Asserts that `$sourceTitle` is among the elements relating to `$target`. */
function checkRelation(ElementInterface $target, string $sourceTitle, string $what): void
{
    $titles = relatingTitles($target);
    check(in_array($sourceTitle, $titles, true), "$what: '$sourceTitle' relates to '$target' (found: " . implode(', ', $titles) . ')');
}

/** Everything a form shows, as text: each node's props (labels, instructions, …) and each control's path. */
function formText(?\CraftCms\Cms\Form\Form $form): string
{
    $walk = function (array $nodes) use (&$walk): array {
        $parts = [];
        foreach ($nodes as $node) {
            $parts[] = json_encode($node->props(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($node instanceof \CraftCms\Cms\Form\Nodes\Field && ($control = $node->getControl())) {
                $parts[] = implode('.', (array)$control->path());
            }
            if (method_exists($node, 'children')) {
                array_push($parts, ...$walk((array)$node->children()));
            }
        }
        return $parts;
    };
    return implode("\n", $walk($form?->nodes() ?? []));
}

/** Number of SQL queries `$fn` runs. */
function queriesDuring(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $fn();
        return count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
}

// Leftovers of an aborted run (a FAIL exits before the cleanup at the end of each section)
foreach ([...Entry::find()->title(['Page via replace*', 'Page via save event', 'ST *', 'DX *', 'RT *', 'E2E *'])->status(null)->drafts(null)->provisionalDrafts(null)->all(), ...Asset::find()->filename(['unused*', 'rt*'])->all()] as $leftover) {
    Elements::deleteElement($leftover, true);
}

$b = Entry::find()->title('Page B')->one() ?? throw new RuntimeException('Run seed.php first');
$rel = $b->getFieldValue('relations');
$field = Fields::getFieldByHandle('relations');
assert($field instanceof ElementRelationsField);

echo "RelationsModel\n";
check($rel instanceof RelationsModel, 'field value is a RelationsModel');
check($rel->getCount() === 12, 'count() = 12');
check($rel->getCountFast() === 12, 'countFast() = 12');
check($rel->getCount(status: 'live') === 11, 'count(status: live) = 11 (excludes the disabled entry)');
check($rel->getCount(status: 'disabled') === 1, 'count(status: disabled) = 1');
check($rel->getCount(sections: 'pages') === 12 && $rel->getCount(sections: 'nope') === 0, 'section filter');
check($rel->getCount(entryTypes: 'page') === 12 && $rel->getCount(entryTypes: 'nope') === 0, 'entry type filter');
check($rel->getIsInUse() && $rel->getIsInUseFast(), 'isInUse / isInUseFast');
check(count($rel->getElements(limit: 10)) === 10 && count($rel->getElements(limit: 10, offset: 10)) === 2, 'limit/offset pagination');
check(count($rel->getSourceElementIds()) === 12, 'getSourceElementIds() = 12 ids');
check(count(iterator_to_array($rel->getElementsIterator([], batchSize: 4), false)) === 12 && count(iterator_to_array($rel->getElementsIterator([], limit: 6, offset: 3, batchSize: 4), false)) === 6, 'the iterator walks every batch (4 at a time), with limit and offset');
$firstTitle = (string)($rel->getElements([], limit: 1)[0] ?? '');
check($firstTitle !== '' && in_array($firstTitle, array_map('strval', $rel->getElements([], search: 'title:"' . $firstTitle . '"')), true) && $rel->getElements([], search: 'title:"no such title anywhere"') === [], 'search narrows the relating elements');
check((clone $rel)->withQueryFactory(fn(string $type) => null)->getElements([]) === [], 'a query factory that gives no query lists nothing (e.g. GraphQL for a type it cannot query)');
check(count($rel->getSitesFast()) === 1, 'sitesFast');

echo "Field\n";
$serialized = $field->serializeValue($rel, $b);
check(is_array($serialized) && ($serialized['count'] ?? null) === 12 && count($serialized['elementIds'] ?? []) === 12, 'serializeValue() exposes count + elementIds (export)');
check($field->serializeValueForDb($rel, $b) === null, 'serializeValueForDb() stores nothing');
$unused = Entry::find()->title('Page A1')->one() ?? throw new RuntimeException('Page A1');
check($unused->getFieldValue('relations')->getCount() === 0, 'Page A1 is unused');

echo "usesElement query param\n";
$n = Entry::find()->usesElement($b)->status(null)->count();
check($n === 12, "Entry::find()->usesElement(\$element) finds the 12 relating entries (got $n)");
check(Entry::find()->usesElement($b->id)->count() === 11, 'usesElement($id) respects the default status filter (11 live)');
$q = Entry::find()->usesElement($b)->status(null);
check((clone $q)->count() === 12, 'usesElement survives cloning the query');
check(Asset::find()->usesElement($b)->count() === 0, 'no assets use Page B');
check(Entry::find()->usesElement(null)->status(null)->count() === Entry::find()->status(null)->count(), 'usesElement(null) does not filter');
check(Entry::find()->usesElement([$b->id, $unused->id])->status(null)->count() === 12, 'usesElement([…]) finds what uses any of them');

echo "Usage element type (one query across all element types)\n";
$usages = Usage::find()->usesElement($b)->status(null)->all();
check(count($usages) === 12 && count(array_filter($usages, fn($e) => $e instanceof Entry)) === 12, 'Usage::find()->usesElement() returns the 12 relating entries as Entry instances');
$twigUsages = renderString('{{ craft.usages().usesElement(id).status(null).count() }}|{{ craft.usages({ usesElement: id, search: "Page A1" }).status(null).ids()|join(",") }}|{{ craft.entries().usesElement(id).count() }}', ['id' => $b->id]);
check(str_starts_with($twigUsages, '12|') && in_array((string)$unused->id, explode(',', explode('|', $twigUsages)[1]), true) && str_ends_with($twigUsages, '|11'), 'Twig: craft.usages() is Usage::find() (usesElement, status, search, criteria like any element query), craft.entries().usesElement() (' . $twigUsages . ')');
check(Usage::find()->usesElement($b)->status(null)->search('A1')->count() === 4, 'search across the Usage query (A1, A10, A11, A12)');
check(Usage::find()->usesElement($b)->status(null)->drafts(true)->count() === 0, 'drafts(true) on the Usage query');
check(Usage::find()->usesElement($b)->status('disabled')->count() === 1, 'status filter on the Usage query');
$titles = array_map(fn(ElementInterface $e): string => (string)$e->title, Usage::find()->usesElement($b)->status(null)->orderBy('title')->limit(2)->all());
check($titles === ['Page A1', 'Page A10'], 'sorting + limit on the Usage query (' . implode(', ', $titles) . ')');

echo "Field UI (Craft's own element index)\n";
$preview = $field->getPreviewHtml($rel, $b);
check(str_contains($preview, 'Page A'), 'preview renders the usages as chips');
check(str_contains($preview, '12 total'), 'preview links to the field with the total count');
$field->previewLimit = 2;
$previewTwo = $field->getPreviewHtml($rel, $b);
$field->previewLimit = 5;
check(substr_count($previewTwo, 'data-id=') < substr_count($preview, 'data-id=') || $previewTwo !== $preview, 'the field setting "Elements in index columns" sets how many usages the preview loads');
$tooSmall = new ElementRelationsField(['previewLimit' => 0]);
check(!$tooSmall->validate(['previewLimit']), 'the preview limit is at least 1');

// as a column of an element index, the field loads the column's data for the whole page at once
$listed = Entry::find()->section('pages')->status(null)->orderBy('elements.id')->all();
$perRow = queriesDuring(function () use ($field, $listed): void {
    RelationsModel::forgetPrefetched();
    foreach ($listed as $entry) {
        $field->getPreviewHtml($entry->getFieldValue('relations'), $entry);
    }
});
$batchedPreviews = [];
$batchedFor = function (?int $limit) use ($field, &$batchedPreviews): int {
    return queriesDuring(function () use ($field, $limit, &$batchedPreviews): void {
        RelationsModel::forgetPrefetched();
        $indexQuery = Entry::find()->section('pages')->status(null)->orderBy('elements.id')->limit($limit);
        $field->modifyElementIndexQuery($indexQuery);
        foreach ($indexQuery->all() as $entry) {
            $batchedPreviews[$entry->id] = $field->getPreviewHtml($entry->getFieldValue('relations'), $entry);
        }
    });
};
$batchedFor(10); // Craft's one-off lookups (schema, field layouts)
$batchedTen = $batchedFor(10);
$batched = $batchedFor(null);
check(count($listed) > 10 && $batched <= $batchedTen + 2 && $batched * 2 < $perRow, "an element index column loads its data for the whole page in a few queries, however many rows ($batchedTen for 10 rows, $batched for " . count($listed) . ", $perRow one row at a time)");
$bBatched = $batchedPreviews[$b->id] ?? '';
check(str_contains($bBatched, '12 total'), 'batched: the same total');
check(str_contains($batchedPreviews[$unused->id] ?? '', \CraftCms\Cms\t('field-value-unused', category: 'element-relations')), 'batched: an unused element still says so');
check((new RelationsModel((int)$b->id, (int)$b->siteId))->forTargetSite()->getCountsByType() === $rel->getCountsByType() && $rel->getCountsByType() !== [], 'batched: the counts per element type are the prefetched ones, and the same');
RelationsModel::forgetPrefetched();
$emptyPage = Entry::find()->id(0);
$field->modifyElementIndexQuery($emptyPage);
check($emptyPage->all() === [] && (new RelationsModel((int)$b->id, (int)$b->siteId))->getCountFast([]) === 12, 'an empty index page prefetches nothing');
RelationsModel::forgetPrefetched();

[$input, $unusedInput] = withWebRequest(fn(): array => [
    HtmlStack::capture(fn(): string => $field->inputHtmlFor($rel, $b)),
    HtmlStack::capture(fn(): string => $field->inputHtmlFor($unused->getFieldValue('relations'), $unused)),
]);
$inputHtml = $input->html . $input->headHtml . $input->bodyHtml;
check(str_contains($input->html, 'element-index') && str_contains($input->html, 'search-container') && str_contains($input->html, 'statusmenubtn'), "input renders Craft's element index with search and status menu");
$decoded = html_entity_decode($inputHtml);
check(str_contains($decoded, 'Craft.createElementIndex') && str_contains($decoded, 'usesElement') && str_contains($decoded, '"inlineEditable":false') && str_contains($decoded, '"canHaveDrafts":true') && str_contains($decoded, 'Usage'), 'the index JS is bound to the Usage type, without inline editing, filtered by usesElement');
check(str_contains($decoded, 'ElementRelationsSearch('), "the list's search box is bound (Craft 6 wraps it in <craft-input>, where the classic index does not find it)");
$control = withWebRequest(fn() => $field->formControl(new \CraftCms\Cms\Field\FieldContext(value: $rel, element: $b, path: ['fields', 'relations'])));
$controlHtml = json_encode($control->props()) ?: '';
check(str_contains($controlHtml, 'data-element-relations-input') && str_contains($controlHtml, 'oninput=\"event.stopPropagation()\"') && str_contains($controlHtml, 'onchange=\"event.stopPropagation()\"'), 'the field stops input/change events at its edge: ticking a usage or typing in its search is no edit of the element (no provisional draft)');
$settings = formText($field->settingsForm());
check(str_contains($settings, 'viewMode') && str_contains($settings, 'showStatusMenu') && str_contains($settings, 'pageSize'), 'field settings offer View Mode, status menu and page size');
$configured = clone $field;
$configured->viewMode = 'cards';
$configured->showStatusMenu = false;
$configured->pageSize = 5;
// Craft keeps a component's validation result: a fresh field per case
$settingsWith = fn(array $settings): ElementRelationsField => new ElementRelationsField(['name' => 'Smoke', 'handle' => 'smokeRelations', 'viewMode' => 'cards', 'showStatusMenu' => false, 'pageSize' => 5, ...$settings]);
check($settingsWith([])->validate(), 'field settings validate');
check(!$settingsWith(['pageSize' => 0])->validate() && !$settingsWith(['viewMode' => 'nope'])->validate(), 'page size must be at least 1, the view mode one Craft knows');
$configuredInput = withWebRequest(fn() => HtmlStack::capture(fn(): string => $configured->inputHtmlFor($rel, $b)));
$configuredAll = html_entity_decode($configuredInput->html . $configuredInput->bodyHtml);
check(str_contains($input->html, 'statusmenubtn') && !str_contains($configuredInput->html, 'statusmenubtn'), 'field setting: status menu hidden');
check(str_contains($configuredAll, '"batchSize":5'), 'field setting: 5 elements per page');
check(str_contains($configuredAll, 'cards'), 'field setting: cards view');
$a1 = Entry::find()->title('Page A1')->one() ?? throw new RuntimeException('Page A1');
Usage::$via[(int)$a1->id] ??= [];
check((string)$a1->getAttributeHtml(Usage::ATTR_TYPE) === 'Entry' && (string)$a1->getAttributeHtml(Usage::ATTR_WHERE) === 'Pages › Page' && (string)$a1->getAttributeHtml(Usage::ATTR_SITE) !== '', 'index columns Type / Where / Site come from the attribute HTML hook (' . $a1->getAttributeHtml(Usage::ATTR_TYPE) . ' / ' . $a1->getAttributeHtml(Usage::ATTR_WHERE) . ')');
check(str_contains((string)$a1->getAttributeHtml('dateUpdated'), '<'), "Craft's own Date Updated column still works on the real element");
$viaBefore = Usage::$via[(int)$a1->id] ?? [];
Usage::$via[(int)$a1->id] = [['fieldId' => 999999, 'type' => 'field', 'disabledIn' => null]];
check(ElementSummary::viaHtml($a1) === '', 'Field column: a field deleted since the cache row was written names nothing (no error)');
Usage::$via[(int)$a1->id] = $viaBefore;
$a1Loaded = Usage::find()->usesElement($b)->status(null)->id($a1->id)->one() ?? throw new RuntimeException('A1 as usage');
check((string)$a1Loaded->getAttributeHtml(Usage::ATTR_FIELD) === 'Related Entries', 'Field column names the field (' . $a1Loaded->getAttributeHtml(Usage::ATTR_FIELD) . ')');
$assetUsage = Asset::find()->volume('test')->orderBy('elements.id')->one() ?? throw new RuntimeException('asset');
Usage::$via[(int)$assetUsage->id] ??= [];
check((string)$assetUsage->getAttributeHtml('postDate') === '' && str_contains((string)$assetUsage->getAttributeHtml('filename'), '.png'), 'columns an element type lacks render empty in a Usage index (asset: Post Date), its own still work (Filename)');
$editionBefore = Context::getHidden(Edition::class);
$columns = [];
foreach ([Edition::Pro, Edition::Solo] as $edition) {
    Context::addHidden(Edition::class, $edition); // in memory
    $columns[$edition->name] = array_keys(Usage::tableAttributes());
}
$editionBefore === null ? Context::forgetHidden(Edition::class) : Context::addHidden(Edition::class, $editionBefore);
check(in_array('authors', $columns['Pro'], true) && !array_intersect(['authors', 'revisionCreator'], $columns['Solo']), 'Craft Solo: no Authors or Last Edited By column to choose (like Entry), Craft Pro: both');
$unusedHtml = $field->getPreviewHtml($unused->getFieldValue('relations'), $unused);
check(str_contains($unusedHtml, 'Unused'), 'preview shows "Unused" for Page A1');
check(str_contains($unusedInput->html, 'Unused'), 'input shows "Unused" for Page A1');
$rowsOfB = fn(): int => DB::table(CacheRecord::TABLE)->where('sourceElementId', $b->id)->count();
$before = $rowsOfB();
(new ResaveElementRelationsJob([['elementId' => 999999999, 'siteId' => (int)$b->siteId], ['elementId' => (int)$b->id, 'siteId' => 999999]]))->handle();
check(!DB::table(CacheRecord::TABLE)->where('sourceElementId', 999999999)->exists() && $rowsOfB() === $before, "a queued refresh of an element deleted for good since, or of a site it is no longer in, does nothing ($before rows of Page B kept)");

echo "GraphQL\n";
// own uid: Craft caches GraphQL results per schema uid; site scopes: the schema must be allowed to read the sites
$schema = new GqlSchema(['uid' => Str::uuid()->toString(), 'name' => 'Smoke', 'scope' => [
    'sections.' . $b->getSection()?->uid . ':read',
    'entrytypes.' . $b->getType()->uid . ':read',
    'elements.inactive:read', // the status argument needs it, like Craft's own queries
    ...Sites::getAllSites(true)->map(fn($site): string => "sites.$site->uid:read")->all(),
]]);
$gqlService = app(Gql::class);
$query = '{ entry(id: ' . $b->id . ') { ... on page_Entry { relations {
    count liveCount: count(status: ["live"]) isInUse
    elements(limit: 2, status: ["disabled"]) { id title }
} } } }';
$result = $gqlService->executeQuery($schema, $query);
$gql = $result['data']['entry']['relations'] ?? null;
check($gql !== null, 'query succeeded: ' . json_encode($result['errors'] ?? null));
check($gql['count'] === 11 && $gql['liveCount'] === 11 && $gql['isInUse'] === true, 'count (live by default in GraphQL, the disabled Page A12 is not counted) / count(status) / isInUse');
check(count($gql['elements']) === 1 && $gql['elements'][0]['title'] === 'Page A12', 'elements(status: ["disabled"]) returns only Page A12');
$result = $gqlService->executeQuery($schema, '{ entry(id: ' . $b->id . ') { ... on page_Entry { relations { all: elements(status: null) { id } } } }
    entries(usesElement: ' . $b->id . ', status: null) { id } live: entries(usesElement: ' . $b->id . ') { id } }');
check(count($result['data']['entry']['relations']['all'] ?? []) === 12, 'an explicit status: null means every status (12, Page A12 included): ' . json_encode($result['errors'] ?? null));
check(count($result['data']['entries'] ?? []) === 12 && count($result['data']['live'] ?? []) === 11, 'Craft’s own entries query takes usesElement: 12 with status: null, 11 by default (' . json_encode($result['errors'] ?? null) . ')');

echo "Translations\n";
// every language Craft ships: the English keys, and each string with the same placeholders ({count}, {names}, …)
$translationsDir = dirname(__DIR__) . '/lang';
$english = require "$translationsDir/en/element-relations.php";
$placeholdersOf = function (string $s): array {
    for ($prev = null; $prev !== $s; $s = (string)preg_replace('/(=\d+|zero|one|two|few|many|other)\s*\{[^{}]*\}/', '', $s)) {
        $prev = $s; // plural branches hold words, not placeholders
    }
    return array_values(array_unique(preg_match_all('/\{([a-zA-Z]+)(?:,|\})/', $s, $m) ? $m[1] : []));
};
$translationProblems = [];
$files = glob("$translationsDir/*/element-relations.php") ?: [];
foreach ($files as $translationFile) {
    $lang = basename(dirname($translationFile));
    $strings = require $translationFile;
    if (array_keys($strings) !== array_keys($english)) {
        $translationProblems[] = "$lang: keys differ";
    }
    foreach ($english as $key => $value) {
        // a plural that only picks words (no #, no plain {x}) may be dropped by a language without that distinction
        $want = array_values(array_filter($placeholdersOf($value), fn(string $x): bool => str_contains($value, '#') || str_contains($value, '{' . $x . '}') || str_contains($value, '{' . $x . ', number') || !str_contains($value, '{' . $x . ', plural')));
        $haveAll = $placeholdersOf((string)($strings[$key] ?? ''));
        $missing = array_diff($want, $haveAll);
        $unknown = array_diff($haveAll, $placeholdersOf($value));
        if ($missing || $unknown) {
            $translationProblems[] = "$lang/$key: " . ($missing ? 'missing ' . json_encode(array_values($missing)) : '') . ($unknown ? ' unknown ' . json_encode(array_values($unknown)) : '');
        }
    }
    // and every string formats in its own language, for one and for many (Craft formats them with ICU)
    $sampleParams = ['numTargets' => 2, 'reverted' => 1, 'names' => 'A, B', 'element' => 'E', 'site' => 'S', 'from' => 'F', 'to' => 'T', 'type' => 'entry', 'types' => 'entries', 'error' => 'x', 'list' => 'L', 'id' => 7, 'sites' => 'S', 'name' => 'N'];
    foreach ($strings as $key => $value) {
        foreach ([1, 5, 22] as $count) {
            $formatted = MessageFormatter::formatMessage($lang, (string)$value, ['count' => $count] + $sampleParams);
            if ($formatted === false || str_contains($formatted, '{')) {
                $translationProblems[] = "$lang/$key does not format with count $count";
                break;
            }
        }
    }
}
check($translationProblems === [], 'every translation file has the English keys and placeholders: ' . implode('; ', array_slice($translationProblems, 0, 5)));
$craftLanguages = array_map('basename', glob(base_path('vendor/craftcms/cms/resources/translations') . '/*', GLOB_ONLYDIR) ?: []);
$pluginLanguages = array_map(fn(string $f): string => basename(dirname($f)), $files);
check(array_diff($craftLanguages, $pluginLanguages) === [], 'every language Craft ships has a translation file (' . count($pluginLanguages) . '; missing: ' . implode(', ', array_diff($craftLanguages, $pluginLanguages)) . ')');
check(\CraftCms\Cms\t('field-value-unused', category: 'element-relations', locale: 'de') !== 'field-value-unused', 'Craft finds the plugin’s translations (German: ' . \CraftCms\Cms\t('field-value-unused', category: 'element-relations', locale: 'de') . ')');

echo "Editions (behind the `features.editions` flag)\n";
check(ElementRelations::editionsFor([]) === ['standard'] && ElementRelations::editionsFor(['editions' => true]) === ['lite', 'pro'], 'flag off: one edition, Craft’s “standard” (its licenses stay valid); flag on: Lite and Pro');
check(ElementRelations::editions() === ['lite', 'pro'], 'the dev project has the flag on (config/craft/element-relations.php)');
check(ElementRelations::isProFor([]) === true, 'flag off: the one edition has everything (isPro() is true)');
$plugin = ElementRelations::instance() ?? throw new RuntimeException('plugin');
$editionBefore = $plugin->edition; // restored at the end of every section that switches, so the dev project keeps its edition
Plugins::switchEdition('element-relations', 'lite');
check(!ElementRelations::isPro() && ElementRelations::instance()?->is('lite'), 'Lite: isPro() is false');
$usedB = Entry::find()->title('Page B')->one() ?? throw new RuntimeException('B');
$unusedA1 = Entry::find()->title('Page A1')->one() ?? throw new RuntimeException('A1');
[$liteUsed, $liteUnused, $liteSettings, $editorSees, $upgrade] = withWebRequest(function () use ($field, $usedB, $unusedA1): array {
    $editor = new User(['username' => 'smoke-upsell-editor', 'admin' => false]);
    $editorSees = ActingUser::run($editor, fn(): bool => ElementRelations::upsells());
    return [
        $field->inputHtmlFor(new RelationsModel((int)$usedB->id, (int)$usedB->siteId), $usedB),
        $field->inputHtmlFor(new RelationsModel((int)$unusedA1->id, (int)$unusedA1->siteId), $unusedA1),
        formText(ElementRelations::instance()?->settingsForm()),
        $editorSees,
        ElementRelations::upgradeUrl(), // as a control panel request builds it
    ];
});
check(str_contains($liteUsed, 'data-element-relations-upsell') && str_contains($liteUsed, \CraftCms\Cms\t('upsell-replace', category: 'element-relations')) && str_contains($liteUsed, $upgrade), 'Lite: under a usage list, admins see what Pro would do here and the link to the Plugin Store');
check(str_contains($liteUnused, \CraftCms\Cms\t('upsell-unused', category: 'element-relations')), 'Lite: next to “Unused”, admins see that Pro finds everything nobody uses');
check(str_contains($liteSettings, \CraftCms\Cms\t('upsell-settings-heading', category: 'element-relations')) && !str_contains($liteSettings, 'protectInUse'), 'Lite: the plugin settings list what Pro adds; no Pro setting');
check(!$editorSees, 'Lite: editors never see it');
Plugins::switchEdition('element-relations', 'pro');
check(ElementRelations::isPro(), 'switching to Pro makes isPro() true');
[$proUsed, $proSettings] = withWebRequest(fn(): array => [$field->inputHtmlFor(new RelationsModel((int)$usedB->id, (int)$usedB->siteId), $usedB), formText(ElementRelations::instance()?->settingsForm())]);
check(!str_contains($proUsed, 'data-element-relations-upsell') && !str_contains($proSettings, 'upsell') && str_contains($proSettings, 'protectInUse'), 'Pro: no upsell anywhere; the settings have the protection switch');
Plugins::switchEdition('element-relations', $editionBefore);
check(ElementRelations::isPro() === ($editionBefore === 'pro'), "restored the $editionBefore edition");

echo "Live save path (save event -> queued refresh job -> cache)\n";
// Seeds never terminate the app, so everything above was built by resave-relations. This exercises the real flow.
$c = Entry::find()->title('Page C')->one() ?? throw new RuntimeException('C');
$section = Sections::getSectionByHandle('pages') ?? throw new RuntimeException('pages');
$live = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => 'Page via save event']);
$live->setFieldValue('relatedEntries', [$c->id]);
Elements::saveElement($live) or fail($live);
flushQueue();
check(in_array('Page via save event', relatingTitles($c), true), 'saving an entry queues a refresh that records its relation');
$rowIds = fn(): array => DB::table(CacheRecord::TABLE)->where('sourceElementId', $live->id)->orderBy('id')->pluck('id')->all();
[$jobsBefore, $idsBefore] = [queuedJobs(ResaveElementRelationsJob::class), $rowIds()];
$untouched = Entry::find()->id($live->id)->one() ?? throw new RuntimeException('live');
Elements::saveElement($untouched) or fail($untouched);
QueueElementsForRefreshService::destruct();
check(queuedJobs(ResaveElementRelationsJob::class) === $jobsBefore, 'saving an entry without changing a custom field queues no refresh');
check(ExtractorService::refreshRelationsForElement($untouched) === false && $rowIds() === $idsBefore, 'a refresh with unchanged relations leaves the cache rows untouched');
$untouched->setFieldValue('relatedEntries', [$b->id]);
Elements::saveElement($untouched) or fail($untouched);
QueueElementsForRefreshService::destruct();
check(queuedJobs(ResaveElementRelationsJob::class) === $jobsBefore + 1, 'changing a relational field queues a refresh');
flushQueue();
check(!in_array('Page via save event', relatingTitles($c), true) && in_array('Page via save event', relatingTitles($b), true), 'the refresh moves the relation from Page C to Page B');
Elements::deleteElement($live, true);
check(!in_array('Page via save event', relatingTitles($c), true), 'deleting the entry removes its relation');

echo "Replace on core fields\n";
Plugins::switchEdition('element-relations', 'pro');
replaceRoundTrip($unused, $b, 'Entries field', fn(?ElementInterface $e, ElementInterface $t): bool => $e !== null && array_map('intval', $e->getFieldValue('relatedEntries')->ids()) === [(int)$t->id]);
Plugins::switchEdition('element-relations', $editionBefore);

echo "Cache hygiene\n";
$failedJobs = DB::table('failed_jobs')->where('failed_at', '>=', $smokeStartedAt)->pluck('exception')->map(fn($e): string => substr((string)$e, 0, 300))->all();
check(!$failedJobs, 'no failed queue jobs' . ($failedJobs ? ': ' . json_encode($failedJobs) : ''));
$revisionRows = DB::table(CacheRecord::TABLE . ' as c')->join('elements as e', 'e.id', '=', 'c.sourceElementId')->whereNotNull('e.revisionId')->count();
check($revisionRows === 0, 'cache holds no rows for revisions');

$ctx = [
    'target' => Entry::find()->title('Page C')->one(),
    'asset' => Asset::find()->volume('test')->orderBy('elements.id')->one(),
];
foreach (glob(__DIR__ . '/smoke/*.php') ?: [] as $file) {
    if (!onlySelected($file)) {
        continue;
    }
    echo ucfirst(basename($file, '.php')) . "\n";
    (require $file)($ctx);
}

// the indexes elements with thousands of usages rely on
$indexes = array_map(fn(array $index): array => $index['columns'], Schema::getIndexes(CacheRecord::TABLE));
foreach (CacheRecord::INDEXES as $columns) {
    check(in_array($columns, $indexes, true), 'index on ' . implode(', ', $columns));
}

// the operations this run created, and older ones whose elements are gone (the history then shows real work only)
Replacement::query()->where('dateCreated', '>=', $smokeStartedAt)->delete();
foreach (Replacement::query()->get() as $operation) {
    if (!Elements::getElementById((int)$operation->fromId, null, $operation->siteId) || !Elements::getElementById((int)$operation->toId, null, $operation->siteId)) {
        $operation->delete();
    }
}

echo "All good.\n";
