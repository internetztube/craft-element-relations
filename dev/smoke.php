<?php
/**
 * Smoke test against a seeded Craft project (see seed.php). Exits non-zero on the first failed assertion.
 * Every `dev/smoke/<name>.php` returns a closure `function (array $ctx): void` with the assertions of one integration.
 *
 * Run: dev/dev.sh smoke   (or: php dev/smoke.php <craft-project-dir>, after seeding and rebuilding the cache)
 */
require ($argv[1] ?? __DIR__) . '/bootstrap.php';
$smokeStartedAt = new DateTime('-1 second'); // the replacement history this run writes is removed again at the end
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

/** `ONLY=matrix,neo` limits the integration seeds/smoke tests to those names (default: all). */
function onlySelected(string $file): bool
{
    $only = array_filter(explode(',', (string)getenv('ONLY')));
    return !$only || in_array(basename($file, '.php'), $only, true);
}

use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use internetztube\elementRelations\elements\Usage;
use internetztube\elementRelations\models\RelationsModel;

function check(bool $ok, string $what): void
{
    echo($ok ? "  ok   " : "  FAIL ") . $what . "\n";
    if (!$ok) {
        exit(1);
    }
}

/**
 * The PHP binary plus the coverage flags this process runs with (dev/dev.sh coverage), for subprocesses that should
 * count towards the coverage of src/ too: the command line, a fresh process for GraphQL schema caches.
 * @return list<string>
 */
function phpCommand(): array
{
    $command = [PHP_BINARY];
    if (ini_get('pcov.enabled')) {
        foreach (['pcov.enabled', 'pcov.directory', 'auto_prepend_file'] as $ini) {
            $command[] = '-d';
            $command[] = $ini . '=' . ini_get($ini);
        }
    }
    return $command;
}

/** Runs `$fn` with web request/response components (Craft's index templates read request params and the response). */
function withWebRequest(callable $fn): mixed
{
    $request = Craft::$app->getRequest();
    $response = Craft::$app->getResponse();
    Craft::$app->set('request', ['class' => craft\web\Request::class, 'isCpRequest' => true, 'cookieValidationKey' => Craft::$app->getConfig()->getGeneral()->securityKey, 'url' => '/admin']);
    Craft::$app->set('response', ['class' => craft\web\Response::class]);
    // Craft's index templates check the current user's permissions (editable sites, ...); a console request has none
    Craft::$app->getUser()->setIdentity(craft\elements\User::find()->username('admin')->one());
    try {
        return $fn();
    } catch (Throwable $e) {
        fwrite(STDERR, "withWebRequest: " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
        throw $e;
    } finally {
        Craft::$app->set('request', $request);
        Craft::$app->set('response', $response);
    }
}

/** Titles of the elements (all sites, all statuses) that relate to `$element`. */
function relatingTitles(ElementInterface $element): array
{
    $titles = array_map(fn($e) => (string)$e, (new RelationsModel($element->id, $element->siteId))->getElements([]));
    sort($titles);
    return $titles;
}

/** Runs the queued relation refreshes (a bootstrapped script never fires EVENT_AFTER_REQUEST by itself). */
function flushQueue(): void
{
    Craft::$app->trigger(yii\base\Application::EVENT_AFTER_REQUEST);
    Craft::$app->getQueue()->run();
}

/**
 * Round trip of the Pro replace feature on one relating element, the way the field's wizard calls it: `$from` is
 * swapped for a temporary element of the same type in `$source` only, the relation must move, `$pointsTo($reloaded,
 * $target)` must see the new reference in the plugin's own field value; then the operation is rolled back and the
 * original checked again.
 * Every extractor's replacer gets exercised through this helper by its integration smoke.
 */
function replaceRoundTrip(ElementInterface $source, ElementInterface $from, string $what, callable $pointsTo): void
{
    $elements = Craft::$app->getElements();
    if ($from instanceof Entry) {
        $target = new Entry(['sectionId' => $from->sectionId, 'typeId' => $from->typeId, 'title' => "RT $what"]);
    } elseif ($from instanceof Asset) {
        $tmp = tempnam(sys_get_temp_dir(), 'er') . '.png';
        file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $target = new Asset(['tempFilePath' => $tmp, 'filename' => 'rt.png', 'title' => "RT $what", 'newFolderId' => $from->folderId, 'volumeId' => $from->volumeId, 'avoidFilenameConflicts' => true]);
        $target->setScenario(Asset::SCENARIO_CREATE);
    } else {
        throw new RuntimeException('replaceRoundTrip supports entries and assets as "from"');
    }
    $elements->saveElement($target) or throw new RuntimeException(json_encode($target->getErrors()));
    $reload = fn() => $source::find()->id($source->id)->siteId($source->siteId)->status(null)->one();
    // an element it does not use (the new target): every replacer says there is nothing to change
    $copy = $reload();
    $changed = [];
    foreach ($copy->getFieldLayout()?->getCustomFields() ?? [] as $field) {
        if ($field instanceof craft\base\Field && internetztube\elementRelations\services\ReplaceService::replaceInField($field, $copy, $target, $from)) {
            $changed[] = $field->handle;
        }
    }
    if (internetztube\elementRelations\services\ReplaceService::replaceInElement($copy, $target, $from)) {
        $changed[] = 'the element itself';
    }
    check(!$changed, "$what: nothing to replace where the element does not use it (" . implode(', ', $changed) . ')');
    $result = internetztube\elementRelations\services\ReplaceService::replace($from, $target, [$source->id]);
    flushQueue();
    check($result['replaced'] === 1 && !$result['skipped'], "$what: replace rewrites the element, counted once whatever its sites (" . json_encode($result) . ')');
    check(in_array((string)$source, relatingTitles($target), true) && !in_array((string)$source, relatingTitles($from), true), "$what: the relation moved to the target");
    check((bool)$pointsTo($reload(), $target), "$what: the field value points to the target");

    // undo through the operation log, exactly what the wizard's Undo and the history's Roll back do
    $operation = internetztube\elementRelations\records\ReplacementRecord::findOne($result['operationId']);
    check($operation && $operation->status === 'applied' && $operation->getChanges(), "$what: the operation is logged with its changes");
    $back = internetztube\elementRelations\services\ReplaceService::rollback($operation);
    flushQueue();
    check($back['restored'] === 1 && !$back['skipped'] && in_array((string)$source, relatingTitles($from), true) && !in_array((string)$source, relatingTitles($target), true) && (bool)$pointsTo($reload(), $from), "$what: rollback restores the original (" . json_encode($back) . ')');
    check(internetztube\elementRelations\records\ReplacementRecord::findOne($operation->id)->status === 'rolledBack', "$what: the operation is marked rolled back");
    $operation->delete(); // keep the dev project's history clean
    $elements->deleteElement($target, true);
}

/** Asserts that `$sourceTitle` is among the elements relating to `$target`. */
function checkRelation(ElementInterface $target, string $sourceTitle, string $what): void
{
    $titles = relatingTitles($target);
    check(in_array($sourceTitle, $titles, true), "$what: '$sourceTitle' relates to '$target' (found: " . implode(', ', $titles) . ")");
}

// Leftovers of an aborted run (a FAIL exits before the cleanup at the end of each section)
foreach ([...Entry::find()->title(['Page via replace*', 'Page via save event', 'ST *', 'DX *', 'RT *', 'E2E *'])->status(null)->drafts(null)->provisionalDrafts(null)->all(), ...Asset::find()->filename(['unused*', 'rt*'])->all()] as $leftover) {
    Craft::$app->getElements()->deleteElement($leftover, true);
}

$b = Entry::find()->title('Page B')->one() ?? throw new RuntimeException('Run seed.php first');
/** @var RelationsModel $rel */
$rel = $b->getFieldValue('relations');
$field = Craft::$app->getFields()->getFieldByHandle('relations');

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
check((clone $rel)->withQueryFactory(fn(string $type) => null)->getElements([]) === [], 'a query factory that gives no query lists nothing (e.g. GraphQL without Commerce)');
check(count($rel->getSitesFast()) === 1, 'sitesFast');

echo "Field\n";
$serialized = $field->serializeValue($rel, $b);
check(($serialized['count'] ?? null) === 12 && count($serialized['elementIds'] ?? []) === 12, 'serializeValue() exposes count + elementIds (export)');
check($field->serializeValueForDb($rel, $b) === null, 'serializeValueForDb() stores nothing');

$unused = Entry::find()->title('Page A1')->one();
check($unused->getFieldValue('relations')->getCount() === 0, 'Page A1 is unused');

echo "usesElement query param\n";
$n = (int)Entry::find()->usesElement($b)->status(null)->count();
check($n === 12, "Entry::find()->usesElement(\$element) finds the 12 relating entries (got $n)");
check((int)Entry::find()->usesElement($b->id)->count() === 11, 'usesElement($id) respects the default status filter (11 live)');
$q = Entry::find()->usesElement($b)->status(null);
check((int)(clone $q)->count() === 12, 'usesElement survives cloning the query');
check((int)Craft::configure(Entry::find(), ['usesElement' => $b->id, 'status' => null])->count() === 12, 'usesElement works as criteria (element index)');
check((int)craft\elements\Asset::find()->usesElement($b)->count() === 0, 'no assets use Page B');
check((int)Entry::find()->usesElement(null)->status(null)->count() === (int)Entry::find()->status(null)->count(), 'usesElement(null) does not filter');

echo "Usage element type (one query across all element types)\n";
$usages = Usage::find()->usesElement($b)->status(null)->all();
check(count($usages) === 12 && count(array_filter($usages, fn($e) => $e instanceof Entry)) === 12, 'Usage::find()->usesElement() returns the 12 relating entries as Entry instances');
$twigUsages = Craft::$app->getView()->renderString('{{ craft.usages().usesElement(id).status(null).count() }}|{{ craft.usages({ usesElement: id, search: "Page A1" }).status(null).ids()|join(",") }}', ['id' => $b->id]);
check(str_starts_with($twigUsages, '12|') && in_array((string)$unused->id, explode(',', substr($twigUsages, 3)), true), 'craft.usages() in Twig is Usage::find(): usesElement, status, search, like any element query (' . $twigUsages . ')');
check((int)Usage::find()->usesElement($b)->status(null)->search('A1')->count() === 4, 'search across the Usage query (A1, A10, A11, A12)');
check((int)Usage::find()->usesElement($b)->status(null)->drafts(true)->count() === 0, 'drafts(true) on the Usage query');
check((int)Usage::find()->usesElement($b)->status('disabled')->count() === 1, 'status filter on the Usage query');
$titles = array_map(fn($e) => $e->title, Usage::find()->usesElement($b)->status(null)->orderBy(['title' => SORT_ASC])->limit(2)->all());
check($titles === ['Page A1', 'Page A10'], 'sorting + limit on the Usage query');

echo "Field UI (Craft's own element index)\n";
$preview = $field->getPreviewHtml($rel, $b);
check(str_contains($preview, 'inline-chips') && str_contains($preview, 'Page A'), 'preview renders Craft element chips');
check(str_contains($preview, 'previewCountBadge'), "preview uses Craft's +N badge");
check(str_contains($preview, '12 total'), 'preview links to the field with the total count');
// Craft shows the first chip and the other loaded elements behind "+N": 5 loaded = "+4"
check(str_contains($preview, '>+4</span>'), 'preview loads 5 usages by default (one chip, the other 4 behind the +N badge)');
$field->previewLimit = 2;
check(str_contains($field->getPreviewHtml($rel, $b), '>+1</span>'), 'the field setting "Elements in index columns" sets how many usages the preview loads');
$field->previewLimit = 5;
check(!(new internetztube\elementRelations\fields\ElementRelationsField(['previewLimit' => 0]))->validate(['previewLimit']), 'the preview limit is at least 1');

// as a column of an element index, the field loads the column's data for the whole page at once
$queriesDuring = function (callable $fn): int {
    $db = Craft::$app->getDb();
    $logger = Yii::getLogger();
    [$profiling, $flush] = [$db->enableProfiling, $logger->flushInterval];
    $db->enableProfiling = true;
    $logger->flushInterval = PHP_INT_MAX;
    $before = count($logger->getProfiling(['yii\db\Command::query']));
    $fn();
    $count = count($logger->getProfiling(['yii\db\Command::query'])) - $before;
    [$db->enableProfiling, $logger->flushInterval] = [$profiling, $flush];
    return $count;
};
$listed = Entry::find()->section('pages')->status(null)->orderBy(['elements.id' => SORT_ASC])->all();
$perRow = $queriesDuring(function () use ($field, $listed): void {
    foreach ($listed as $entry) {
        $field->getPreviewHtml($entry->getFieldValue('relations'), $entry);
    }
});
$batchedPreviews = [];
$batchedFor = function (?int $limit) use ($queriesDuring, $field, &$batchedPreviews): int {
    return $queriesDuring(function () use ($field, $limit, &$batchedPreviews): void {
        $indexQuery = Entry::find()->section('pages')->status(null)->orderBy(['elements.id' => SORT_ASC])->limit($limit);
        $field->modifyElementIndexQuery($indexQuery);
        foreach ($indexQuery->all() as $entry) {
            $batchedPreviews[$entry->id] = $field->getPreviewHtml($entry->getFieldValue('relations'), $entry);
        }
    });
};
$batchedFor(10); // Craft's one-off lookups (schema, field layouts)
$batchedTen = $batchedFor(10);
$batched = $batchedFor(null);
check(count($listed) > 10 && $batched <= $batchedTen + 2 && $batched * 4 < $perRow, "an element index column loads its data for the whole page in a few queries, however many rows ($batchedTen for 10 rows, $batched for " . count($listed) . ", $perRow one row at a time)");
$chipIds = fn(string $html): array => preg_match_all('/data-id="(\d+)"/', $html, $m) ? array_map(intval(...), $m[1]) : [];
$newest = (int)(new craft\db\Query())
    ->select(['e.id'])
    ->from(['c' => '{{%elementrelations_cache}}'])
    ->innerJoin(['e' => craft\db\Table::ELEMENTS], '[[e.id]] = [[c.sourcePrimaryOwnerId]]')
    ->leftJoin(['d' => craft\db\Table::DRAFTS], '[[d.id]] = [[e.draftId]]')
    ->where(['c.targetElementId' => $b->id, 'e.revisionId' => null, 'e.dateDeleted' => null])
    ->andWhere(['or', ['d.id' => null], ['d.provisional' => false]])
    ->orderBy(['e.dateUpdated' => SORT_DESC, 'e.id' => SORT_DESC])
    ->scalar();
$bBatched = $batchedPreviews[$b->id] ?? '';
check(str_contains($bBatched, '12 total') && str_contains($bBatched, '>+4</span>') && ($chipIds($bBatched)[0] ?? null) === $newest, 'batched: the same total and number of usages, the most recently updated one first (' . json_encode(['chips' => $chipIds($bBatched), 'newest' => $newest]) . ')');
check(str_contains($batchedPreviews[$unused->id] ?? '', Craft::t('element-relations', 'field-value-unused')), 'batched: an unused element still says so');
internetztube\elementRelations\models\RelationsModel::forgetPrefetched();

[$input, $unusedInput] = withWebRequest(fn() => [
    $field->getInputHtml($rel, $b),
    $field->getInputHtml($unused->getFieldValue('relations'), $unused),
]);
check(str_contains($input, 'class="element-index') && str_contains($input, 'search-container') && str_contains($input, 'statusmenubtn'), "input renders Craft's element index with search and status menu");
check(!str_contains($input, 'export-btn') || str_contains($input, 'export-btn" class="btn hidden"'), 'export button stays hidden');
$js = implode("\n", array_merge(...array_values(Craft::$app->getView()->js ?: [[]])));
$colOpts = html_entity_decode($input);
check(str_contains($colOpts, '"attr":"status"') && str_contains($colOpts, '"attr":"postDate"') && str_contains($colOpts, '"attr":"link"'), 'the View menu offers Status, Post Date and Link as table columns');
check(str_contains($colOpts, '"attr":"section"') && str_contains($colOpts, '"attr":"type"') && !str_contains($colOpts, '"attr":"filename"'), 'the View menu offers the columns of the element types in this list (Section, Entry Type; no Filename: no asset uses it), like Craft\'s indexes');
check(str_contains($js, 'Craft.createElementIndex') && str_contains($js, 'usesElement') && str_contains($js, '"inlineEditable":false') && str_contains($js, '"canHaveDrafts":true') && str_contains($js, 'Usage'), 'the index JS is bound to the Usage type, without inline editing, filtered by usesElement');
$settingsHtml = withWebRequest(fn() => $field->getSettingsHtml());
check(str_contains($settingsHtml, 'name="viewMode"') && str_contains($settingsHtml, 'name="showStatusMenu"') && str_contains($settingsHtml, 'name="pageSize"'), 'field settings offer View Mode, status menu and page size');
$configured = clone $field;
$configured->viewMode = 'cards';
$configured->showStatusMenu = false;
$configured->pageSize = 5;
check($configured->validate(), 'field settings validate');
$configured->pageSize = 0;
check(!$configured->validate() && $configured->pageSize === 0, 'page size must be at least 1');
$configured->pageSize = 5;
Craft::$app->getView()->clear();
$configuredInput = withWebRequest(fn() => $configured->getInputHtml($rel, $b));
$configuredJs = implode("\n", array_merge(...array_values(Craft::$app->getView()->js ?: [[]])));
check(str_contains($input, 'data-status="live"') && !str_contains($configuredInput, 'data-status="live"'), 'field setting: statuses hidden (Craft keeps Drafts/Trashed for admins)');
check(str_contains($configuredJs, '"batchSize":5'), 'field setting: 5 elements per page');
check(str_contains($configuredInput, 'cards'), 'field setting: cards view');
$a1 = Entry::find()->title('Page A1')->one();
check($a1->getAttributeHtml(Usage::ATTR_TYPE) === 'Entry' && $a1->getAttributeHtml(Usage::ATTR_WHERE) === 'Pages › Page' && $a1->getAttributeHtml(Usage::ATTR_SITE) !== '', 'index columns Type / Where / Site come from the attribute HTML hook');
check(str_contains($a1->getAttributeHtml('dateUpdated'), '<'), "Craft's own Date Updated column still works on the real element");
$a1Usage = Usage::find()->usesElement($b)->status(null)->id($a1->id)->one();
$a1Usage->setAuthorIds([craft\elements\User::find()->username('admin')->one()->id]); // seeded entries have no author
$authorsHtml = $a1Usage->getAttributeHtml('authors'); // Craft Solo has no Authors column
check((Craft::$app->edition === craft\enums\CmsEdition::Solo ? $authorsHtml === '' : str_contains($authorsHtml, 'chip')) && str_contains($a1Usage->getAttributeHtml('status'), 'status-label') && $a1Usage->getAttributeHtml('postDate') !== '' && str_contains($a1Usage->getAttributeHtml('link'), 'href'), 'Authors / Status / Post Date / Link columns render on an entry loaded through Usage');
$craftEdition = Craft::$app->edition;
$columns = [];
foreach ([craft\enums\CmsEdition::Pro, craft\enums\CmsEdition::Solo] as $edition) {
    Craft::$app->edition = $edition; // in memory, like permissions.php
    $columns[$edition->name] = array_keys(Usage::tableAttributes());
}
Craft::$app->edition = $craftEdition;
check(in_array('authors', $columns['Pro'], true) && !array_intersect(['authors', 'revisionCreator'], $columns['Solo']), 'Craft Solo: no Authors or Last Edited By column to choose (like Entry), Craft Pro: both');
$viaBefore = Usage::$via[(int)$a1->id] ?? null;
Usage::$via[(int)$a1->id] = [['fieldId' => 999999, 'type' => 'field', 'disabledIn' => null]];
check(internetztube\elementRelations\helpers\ElementSummary::viaHtml($a1) === '', 'Field column: a field deleted since the cache row was written names nothing (no error)');
Usage::$via[(int)$a1->id] = $viaBefore ?? [];
$relationsField = Craft::$app->getFields()->getFieldByHandle('relations');
$goneLayout = new craft\models\FieldLayout(['type' => Entry::class]);
$goneTab = new craft\models\FieldLayoutTab(['name' => 'Usages', 'layout' => $goneLayout]);
$goneTab->setElements([new craft\fieldlayoutelements\CustomField(null, ['fieldUid' => craft\helpers\StringHelper::UUID()]), new craft\fieldlayoutelements\CustomField($relationsField)]);
$goneLayout->setTabs([$goneTab]);
$withGoneField = new class extends Entry {
    public ?craft\models\FieldLayout $layout = null;

    public function getFieldLayout(): ?craft\models\FieldLayout
    {
        return $this->layout;
    }
};
$withGoneField->layout = $goneLayout;
check($relationsField->getTabHash($withGoneField) === $goneTab->getHtmlId(), 'the link to the tab of the field: a deleted field still in the layout is passed over');
$cacheRowsOfB = fn(): int => (int)(new craft\db\Query())->from('{{%elementrelations_cache}}')->where(['sourceElementId' => $b->id])->count();
$rowsOfB = $cacheRowsOfB();
(new internetztube\elementRelations\jobs\ResaveElementRelationsJob(['items' => [['elementId' => 999999999, 'siteId' => $b->siteId], ['elementId' => $b->id, 'siteId' => 999999]]]))->execute(Craft::$app->getQueue());
check(!(new craft\db\Query())->from('{{%elementrelations_cache}}')->where(['sourceElementId' => 999999999])->exists() && $cacheRowsOfB() === $rowsOfB, "a queued refresh of an element deleted for good since, or of a site it is no longer in, does nothing ($rowsOfB rows of Page B kept)");
$assetUsage = Asset::find()->volume('test')->orderBy(['elements.id' => SORT_ASC])->one(); // the seeded test asset (seed.php)
Usage::$via[(int)$assetUsage->id] ??= [];
check($assetUsage->getAttributeHtml('authors') === '' && $assetUsage->getAttributeHtml('postDate') === '' && str_contains($assetUsage->getAttributeHtml('filename'), '.png'), 'columns an element type lacks render empty in a Usage index (asset: Authors, Post Date), its own still work (Filename)');
$a1Loaded = Usage::find()->usesElement($b)->status(null)->id($a1->id)->one();
$via = $a1Loaded->getAttributeHtml(Usage::ATTR_FIELD);
check($via === 'Related Entries', 'Field column names the field (no link: Craft drops URL hashes)');
$unusedHtml = $field->getPreviewHtml($unused->getFieldValue('relations'), $unused);
check(str_contains($unusedHtml, 'Unused') && str_contains($unusedHtml, 'status-label'), 'preview shows a Craft "Unused" status label for Page A1');
check(str_contains($unusedInput, 'status-label'), 'input shows the "Unused" status label for Page A1');

echo "GraphQL\n";
// own uid: Craft caches GraphQL results per schema uid; site scopes: the schema must be allowed to read the sites
$schema = new craft\models\GqlSchema(['uid' => craft\helpers\StringHelper::UUID(), 'scope' => [
    'sections.' . $b->getSection()->uid . ':read',
    'entrytypes.' . $b->getType()->uid . ':read',
    'elements.inactive:read', // the status argument needs it, like Craft's own queries
    ...array_map(fn($site) => "sites.$site->uid:read", Craft::$app->getSites()->getAllSites(true)),
]]);
$query = '{ entry(id: ' . $b->id . ') { ... on page_Entry { relations {
    count liveCount: count(status: ["live"]) isInUse
    elements(limit: 2, status: ["disabled"]) { id title }
} } } }';
$result = Craft::$app->getGql()->executeQuery($schema, $query);
$gql = $result['data']['entry']['relations'] ?? null;
check($gql !== null, 'query succeeded: ' . json_encode($result['errors'] ?? null));
check($gql['count'] === 11 && $gql['liveCount'] === 11 && $gql['isInUse'] === true, 'count (live by default in GraphQL, the disabled Page A12 is not counted) / count(status) / isInUse');
check(count($gql['elements']) === 1 && $gql['elements'][0]['title'] === 'Page A12', 'elements(status: ["disabled"]) returns only Page A12');
$result = Craft::$app->getGql()->executeQuery($schema, '{ entry(id: ' . $b->id . ') { ... on page_Entry { relations { all: elements(status: null) { id } } } }
    entries(usesElement: ' . $b->id . ', status: null) { id } live: entries(usesElement: ' . $b->id . ') { id } }');
check(count($result['data']['entry']['relations']['all'] ?? []) === 12, 'an explicit status: null means every status (12, Page A12 included): ' . json_encode($result['errors'] ?? null));
check(count($result['data']['entries'] ?? []) === 12 && count($result['data']['live'] ?? []) === 11, 'Craft’s own entries query takes usesElement: 12 with status: null, 11 by default');
$oneUsage = Entry::find()->usesElement($b)->one();
$result = Craft::$app->getGql()->executeQuery($schema, '{ entries(usesElement: ' . $b->id . ', siteSettingsId: [' . $oneUsage->siteSettingsId . ', 999999999]) { id } }');
check(array_column($result['data']['entries'] ?? [], 'id') === [(string)$oneUsage->id], 'usesElement with siteSettingsId: the intersection (' . json_encode($result['errors'] ?? null) . ')');
internetztube\elementRelations\gql\handlers\UsesElement::$maxUsages = 1;
$result = Craft::$app->getGql()->executeQuery($schema, '{ entries(usesElement: ' . $b->id . ') { id } }');
internetztube\elementRelations\gql\handlers\UsesElement::$maxUsages = 50000;
check(str_contains(json_encode($result['errors'] ?? []), 'more than 1 usages'), 'usesElement refuses more usages than the limit, with a hint: ' . json_encode($result['errors'][0]['message'] ?? null));

$cModel = new RelationsModel((int)Entry::find()->title('Page C')->one()->id, (int)$b->siteId);
$notEntries = fn(array $elements): array => array_filter($elements, fn(ElementInterface $e): bool => !$e instanceof Entry);
check($notEntries($cModel->getElements(sections: ['pages'], status: null)) === [] && $notEntries($cModel->getElements(entryTypes: ['page'], status: null)) === [] && $cModel->getCount(sections: ['pages'], status: null) < $cModel->getCount(status: null), 'sections / entryTypes: only entries (the product, category and tag using Page C are not counted as “pages”)');
check(array_filter($cModel->getElements(status: ['disabled']), fn(ElementInterface $e): bool => !$e::hasStatuses()) === [] && array_filter($cModel->getElements(status: ['enabled']), fn(ElementInterface $e): bool => $e instanceof craft\elements\Tag) !== [], 'status: a type without statuses (tag) only matches “enabled”, never “disabled”');
$mixed = $cModel->getElements(status: ['live', 'enabled'], drafts: false);
$has = fn(string $class): bool => array_filter($mixed, fn(ElementInterface $e): bool => is_a($e, $class)) !== [];
check($has(Entry::class) && $has(craft\elements\Category::class) && $has('craft\commerce\elements\Product') && $has(craft\elements\Tag::class), 'status [“live”, “enabled”]: each type takes the words it knows (entries and the product live, categories enabled, the tag), none is dropped');

echo "Translations\n";
// every language Craft ships: the English keys, and each string with the same placeholders ({count}, {names}, …)
$translationsDir = dirname(__DIR__) . '/src/translations';
$english = require "$translationsDir/en/element-relations.php";
$placeholdersOf = function (string $s): array {
    for ($prev = null; $prev !== $s; $s = preg_replace('/(=\d+|zero|one|two|few|many|other)\s*\{[^{}]*\}/', '', $s)) $prev = $s; // plural branches hold words, not placeholders
    return array_values(array_unique(preg_match_all('/\{([a-zA-Z]+)(?:,|\})/', $s, $m) ? $m[1] : []));
};
$translationProblems = [];
foreach (glob("$translationsDir/*/element-relations.php") as $translationFile) {
    $lang = basename(dirname($translationFile));
    $strings = require $translationFile;
    if (array_keys($strings) !== array_keys($english)) {
        $translationProblems[] = "$lang: keys differ";
    }
    foreach ($english as $key => $value) {
        // a plural that only picks words (no #, no plain {x}) may be dropped by a language without that distinction
        $want = array_values(array_filter($placeholdersOf($value), fn(string $x): bool => str_contains($value, '#') || str_contains($value, '{' . $x . '}') || str_contains($value, '{' . $x . ', number') || !str_contains($value, '{' . $x . ', plural'))); sort($want);
        $haveAll = $placeholdersOf((string)($strings[$key] ?? ''));
        $missing = array_diff($want, $haveAll);
        $unknown = array_diff($haveAll, $placeholdersOf($value));
        if ($missing || $unknown) {
            $translationProblems[] = "$lang/$key: " . ($missing ? 'missing ' . json_encode(array_values($missing)) : '') . ($unknown ? ' unknown ' . json_encode(array_values($unknown)) : '');
        }
    }
}
$craftLanguages = array_map('basename', glob(Craft::getAlias('@craft/translations') . '/*', GLOB_ONLYDIR));
$pluginLanguages = array_map(fn(string $f) => basename(dirname($f)), glob("$translationsDir/*/element-relations.php"));
// and every string formats in its own language, for one and for many (Craft formats them with ICU)
$sampleParams = ['count' => 3, 'numTargets' => 2, 'reverted' => 1, 'names' => 'A, B', 'element' => 'E', 'site' => 'S', 'from' => 'F', 'to' => 'T', 'type' => 'entry', 'types' => 'entries', 'error' => 'x', 'list' => 'L', 'id' => 7, 'sites' => 'S', 'name' => 'N'];
foreach (glob("$translationsDir/*/element-relations.php") as $translationFile) {
    $lang = basename(dirname($translationFile));
    foreach (require $translationFile as $key => $value) {
        foreach ([1, 5, 22] as $n) {
            $formatted = MessageFormatter::formatMessage($lang, $value, ['count' => $n] + $sampleParams);
            if ($formatted === false || str_contains($formatted, '{')) {
                $translationProblems[] = "$lang/$key does not format with count $n";
                break;
            }
        }
    }
}
check($translationProblems === [], 'every translation file has the English keys and placeholders: ' . implode('; ', array_slice($translationProblems, 0, 5)));
check(array_diff($craftLanguages, $pluginLanguages) === [], 'every language Craft ships has a translation file (' . count($pluginLanguages) . '; missing: ' . implode(', ', array_diff($craftLanguages, $pluginLanguages)) . ')');

echo "Editions (behind the `features.editions` flag)\n";
$plugin = internetztube\elementRelations\ElementRelations::class;
check($plugin::editionsFor([]) === ['standard'] && $plugin::editionsFor(['editions' => true]) === ['lite', 'pro'], 'flag off: one edition, Craft’s “standard” like 3.1 (its licenses stay valid); flag on: Lite and Pro');
check($plugin::editions() === ['lite', 'pro'], 'the dev project has the flag on (config/element-relations.php)');
check($plugin::isProFor([]) === true, 'flag off: the one edition has everything (isPro() is true)');
$editionBefore = $plugin::getInstance()->edition; // restored at the end of every section that switches, so the dev project keeps its edition
Craft::$app->getPlugins()->switchEdition('element-relations', 'lite');
check(!$plugin::isPro() && $plugin::getInstance()->is('lite'), 'Lite: isPro() is false');
// Lite with Pro available: admins are pointed to Pro, quietly, where it would help; editors never
$upsellField = Craft::$app->getFields()->getFieldByHandle('relations');
$usedB = Entry::find()->title('Page B')->one();
$unusedA1 = Entry::find()->title('Page A1')->one();
[$liteUsed, $liteUnused, $liteSettings, $editorSees, $upgrade] = withWebRequest(function () use ($upsellField, $usedB, $unusedA1, $plugin) {
    $editor = new craft\elements\User(['username' => 'smoke-upsell-editor', 'admin' => false]);
    $admin = Craft::$app->getUser()->getIdentity();
    Craft::$app->getUser()->setIdentity($editor);
    $editorSees = $plugin::upsells();
    Craft::$app->getUser()->setIdentity($admin);
    Craft::$app->getView()->setTemplateMode('cp');
    return [
        $upsellField->getInputHtml(new RelationsModel($usedB->id, $usedB->siteId), $usedB),
        $upsellField->getInputHtml(new RelationsModel($unusedA1->id, $unusedA1->siteId), $unusedA1),
        Craft::$app->getView()->renderTemplate('element-relations/settings', ['settings' => $plugin::getInstance()->getSettings()]),
        $editorSees,
        $plugin::upgradeUrl(), // as a control panel request builds it
    ];
});
check(str_contains($liteUsed, 'data-element-relations-upsell') && str_contains($liteUsed, Craft::t('element-relations', 'upsell-replace')) && str_contains($liteUsed, $upgrade), 'Lite: under a usage list, admins see what Pro would do here and the link to the Plugin Store');
check(str_contains($liteUnused, Craft::t('element-relations', 'upsell-unused')), 'Lite: next to “Unused”, admins see that Pro finds everything nobody uses');
check(str_contains($liteSettings, Craft::t('element-relations', 'upsell-settings-heading')) && str_contains($liteSettings, $upgrade) && !str_contains($liteSettings, 'protectInUse'), 'Lite: the plugin settings list what Pro adds, with Upgrade; no Pro setting');
check(!$editorSees, 'Lite: editors never see it');
Craft::$app->getPlugins()->switchEdition('element-relations', 'pro');
check($plugin::isPro(), 'switching to Pro makes isPro() true');
[$proUsed, $proSettings] = withWebRequest(function () use ($upsellField, $usedB, $plugin) {
    Craft::$app->getView()->setTemplateMode('cp');
    return [$upsellField->getInputHtml(new RelationsModel($usedB->id, $usedB->siteId), $usedB), Craft::$app->getView()->renderTemplate('element-relations/settings', ['settings' => $plugin::getInstance()->getSettings()])];
});
check(!str_contains($proUsed, 'data-element-relations-upsell') && !str_contains($proSettings, 'data-element-relations-upsell') && str_contains($proSettings, 'protectInUse'), 'Pro: no upsell anywhere; the settings have the protection switch');
Craft::$app->getPlugins()->switchEdition('element-relations', $editionBefore);
check($plugin::isPro() === ($editionBefore === 'pro'), "restored the $editionBefore edition");

echo "Live save path (save event -> queued refresh job -> cache)\n";
// Seeds never fire EVENT_AFTER_REQUEST, so everything above was built by resave-relations. This exercises the real flow.
$c = Entry::find()->title('Page C')->one();
$section = Craft::$app->getEntries()->getSectionByHandle('pages');
foreach (Entry::find()->title('Page via save event')->status(null)->drafts(null)->all() as $leftover) { // from an aborted run
    Craft::$app->getElements()->deleteElement($leftover, true);
}
$live = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => 'Page via save event']);
$live->setFieldValue('relatedEntries', [$c->id]);
Craft::$app->getElements()->saveElement($live) or throw new RuntimeException(json_encode($live->getErrors()));
Craft::$app->trigger(yii\base\Application::EVENT_AFTER_REQUEST);
Craft::$app->getQueue()->run();
check(in_array('Page via save event', relatingTitles($c), true), 'saving an entry queues a refresh that records its relation');
$queueRows = fn() => (int)(new craft\db\Query())->from('{{%queue}}')->where(['description' => 'Resave Element Relations'])->count(); // Craft queues its own jobs on save (revision pruning, ...)
$rowIds = fn() => (new craft\db\Query())->select('id')->from('{{%elementrelations_cache}}')->where(['sourceElementId' => $live->id])->orderBy('id')->column();
[$jobsBefore, $idsBefore] = [$queueRows(), $rowIds()];
$untouched = Entry::find()->id($live->id)->one();
Craft::$app->getElements()->saveElement($untouched) or throw new RuntimeException(json_encode($untouched->getErrors()));
Craft::$app->trigger(yii\base\Application::EVENT_AFTER_REQUEST);
check($queueRows() === $jobsBefore, 'saving an entry without changing a custom field queues no refresh');
check(internetztube\elementRelations\services\ExtractorService::refreshRelationsForElement($untouched) === false && $rowIds() === $idsBefore, 'a refresh with unchanged relations leaves the cache rows untouched');
$untouched->setFieldValue('relatedEntries', [$b->id]);
Craft::$app->getElements()->saveElement($untouched) or throw new RuntimeException(json_encode($untouched->getErrors()));
Craft::$app->trigger(yii\base\Application::EVENT_AFTER_REQUEST);
check($queueRows() === $jobsBefore + 1, 'changing a relational field queues a refresh');
Craft::$app->getQueue()->run();
check(!in_array('Page via save event', relatingTitles($c), true) && in_array('Page via save event', relatingTitles($b), true), 'the refresh moves the relation from Page C to Page B');
Craft::$app->getElements()->deleteElement($live, true);
check(!in_array('Page via save event', relatingTitles($c), true), 'deleting the entry removes its relation');

echo "Replace (Pro) on core fields\n";
Craft::$app->getPlugins()->switchEdition('element-relations', 'pro');
replaceRoundTrip(Entry::find()->title('Page A1')->one(), $b, 'Entries field', fn(Entry $e, $t) => array_map('intval', $e->getFieldValue('relatedEntries')->ids()) === [$t->id]);
Craft::$app->getPlugins()->switchEdition('element-relations', $editionBefore);

echo "Cache hygiene\n";
$failedJobs = (new craft\db\Query())->select(['description', 'error'])->from('{{%queue}}')->where(['fail' => true])->all();
check(!$failedJobs, 'no failed queue jobs' . ($failedJobs ? ': ' . json_encode($failedJobs) : ''));
$revisionRows = (new craft\db\Query())
    ->from(['c' => '{{%elementrelations_cache}}'])
    ->innerJoin(['e' => craft\db\Table::ELEMENTS], '[[e.id]] = [[c.sourceElementId]]')
    ->where(['not', ['e.revisionId' => null]])
    ->count();
check((int)$revisionRows === 0, 'cache holds no rows for revisions');

$ctx = [
    'target' => Entry::find()->title('Page C')->one(),
    'asset' => Asset::find()->volume('test')->orderBy(['elements.id' => SORT_ASC])->one(),
];
foreach (glob(__DIR__ . '/smoke/*.php') as $file) {
    if (!onlySelected($file)) {
        continue;
    }
    echo ucfirst(basename($file, '.php')) . "\n";
    (require $file)($ctx);
}

// the indexes elements with thousands of usages rely on (fresh installs and upgrades alike)
foreach (internetztube\elementRelations\migrations\m261024_000000_cache_indexes::INDEXES as $columns) {
    check(craft\helpers\Db::findIndex('{{%elementrelations_cache}}', $columns) !== null, 'index on ' . implode(', ', $columns));
}

// the operations this run created, and older ones whose elements are gone (the history then shows real work only,
// e.g. the demo's for screenshots)
internetztube\elementRelations\records\ReplacementRecord::deleteAll(['>=', 'dateCreated', craft\helpers\Db::prepareDateForDb($smokeStartedAt)]);
foreach (internetztube\elementRelations\records\ReplacementRecord::find()->all() as $operation) {
    $elements = Craft::$app->getElements();
    if (!$elements->getElementById($operation->fromId, null, $operation->siteId) || !$elements->getElementById($operation->toId, null, $operation->siteId)) {
        $operation->delete();
    }
}

echo "All good.\n";
