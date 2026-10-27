<?php
/**
 * GUI-level test of the control panel paths, against a running dev server (dev/dev.sh serve, dev/dev.sh e2e):
 * logs in over HTTP, renders the edit page, asks Craft's element index endpoint for the usages exactly like the
 * field's list does, submits ticked ids to the replace endpoint like its "Replace…" action and verifies the result
 * through Craft.
 * Catches what the console smoke cannot: permissions, request validation, what the index really sends (owner ids).
 *
 *   php dev/e2e.php <projectDir> [baseUrl]   (default http://localhost:8080, admin / password)
 */

use craft\elements\Entry;
use internetztube\elementRelations\elements\Usage;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\models\RelationsModel;

require $argv[1] . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
$base = rtrim($argv[2] ?? 'http://localhost:8080', '/');

function check(bool $ok, string $what): void
{
    echo($ok ? "  ok   " : "  FAIL ") . $what . "\n";
    if (!$ok) {
        exit(1);
    }
}

/** Minimal HTTP client with a cookie jar and Craft's CSRF token, like the control panel's JavaScript. */
final class Http
{
    public string $jar;
    public ?string $csrf = null;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'e2e-cookies');
    }

    /** @return array{0:int,1:string,2:array} status, body, decoded JSON (or []) */
    public function request(string $method, string $path, array $data = [], bool $json = true): array
    {
        $ch = curl_init($this->base . $path);
        $headers = ['X-Requested-With: XMLHttpRequest'];
        if ($json) {
            $headers[] = 'Accept: application/json';
        }
        if ($this->csrf) {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'element-relations-e2e', // Craft refuses sessions without a user agent (requireUserAgentAndIpForSession)
        ]);
        if ($method === 'POST') {
            // JSON body, like Craft.sendActionRequest() in the control panel (keeps true/false/null as such)
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $body = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = $json ? (json_decode($body, true) ?: []) : [];
        return [$status, $body, $decoded];
    }

    public function action(string $route, array $data = [], string $method = 'POST'): array
    {
        return $this->request($method, '/index.php?p=admin/actions/' . $route, $data); // like the control panel's JS: a CP action request
    }

    public function page(string $path): array
    {
        return $this->request('GET', $path, [], false);
    }
}

$elements = Craft::$app->getElements();
$plugins = Craft::$app->getPlugins();
$flush = function (): void {
    Craft::$app->trigger(yii\base\Application::EVENT_AFTER_REQUEST);
    Craft::$app->getQueue()->run();
};
$relating = fn($element) => array_map(fn($e) => (string)$e, (new RelationsModel($element->id, $element->siteId))->getElements([]));
// the web process reads the edition from project config, which a console process persists only at the end of its
// run, so flush it after every switch
$switchEdition = function (string $edition) use ($plugins): void {
    $plugins->switchEdition('element-relations', $edition);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
};
$editionBefore = ElementRelations::getInstance()->edition;
$startedAt = new DateTime('-1 second');
$switchEdition('pro');

// --- fixtures (through Craft, as the console smoke does)
echo "Fixtures\n";
$section = Craft::$app->getEntries()->getSectionByHandle('pages') ?? throw new RuntimeException('Run the seed first');
$typeId = $section->getEntryTypes()[0]->id;
foreach ([...Entry::find()->title('E2E *')->status(null)->drafts(null)->provisionalDrafts(null)->all(), ...craft\elements\Asset::find()->title('E2E *')->status(null)->all()] as $old) {
    $elements->deleteElement($old, true); // leftovers of an aborted run
}
$make = function (string $title, array $fields = []) use ($section, $typeId, $elements): Entry {
    $entry = new Entry(['sectionId' => $section->id, 'typeId' => $typeId, 'title' => $title]);
    $entry->setFieldValues($fields);
    $elements->saveElement($entry) or throw new RuntimeException(json_encode($entry->getErrors()));
    return $entry;
};
$from = $make('E2E from');
$target = $make('E2E target');
$sources = [
    'field' => $make('E2E source (field)', ['relatedEntries' => [$from->id]]),
];
if (($matrix = Craft::$app->getFields()->getFieldByHandle('teasers')) instanceof craft\fields\Matrix) {
    $sources['nested'] = $make('E2E source (nested)', ['teasers' => ['new1' => ['type' => $matrix->getEntryTypes()[0]->handle, 'fields' => ['relatedEntries' => [$from->id]]]]]);
}
if (Craft::$app->getFields()->getFieldByHandle('ckeditor')) {
    $sources['ckeditor'] = $make('E2E source (ckeditor)', ['ckeditor' => '<p><a href="{entry:' . $from->id . ':url||' . $from->getUrl() . '}">x</a></p>']);
}
$flush();
check(count(array_intersect(array_map('strval', $sources), $relating($from))) === count($sources), count($sources) . ' sources relate to "E2E from"');

// --- login like the control panel does
echo "Login\n";
$http = new Http($base);
[$status, , $info] = $http->action('users/session-info', [], 'GET'); // a POST without a token is rejected by CSRF validation
check($status === 200 && !empty($info['csrfTokenValue']), "session-info gives a CSRF token (HTTP $status)");
$http->csrf = $info['csrfTokenValue'];
[$status, $body, $login] = $http->action('users/login', ['loginName' => 'admin', 'password' => 'password']);
check($status === 200 && !empty($login['success'] ?? $login['returnUrl'] ?? null), "login succeeds (HTTP $status: " . substr($body, 0, 120) . ')');
[$status, , $info] = $http->action('users/session-info', [], 'GET');
check(($info['isGuest'] ?? true) === false && ($info['username'] ?? '') === 'admin', 'session belongs to admin');
$http->csrf = $info['csrfTokenValue'] ?? $http->csrf;

// --- the edit page renders the field with Craft's index, telling it which element "Replace…" replaces
echo "Edit page\n";
[$status, $html] = $http->page('/admin/content/entries/pages/' . $from->id);
check($status === 200, "edit page renders (HTTP $status)");
check(str_contains($html, 'Craft.createElementIndex') && str_contains($html, '"usesElement":' . $from->id) && str_contains($html, '"usesElementSite":' . $from->siteId) && str_contains($html, '"allSites":true'), 'field embeds the Usage index filtered by this element in its site, its relating elements from every site');
check(str_contains($html, 'data-element-relations-from="' . $from->id . '"'), 'the field tells its index which element "Replace…" replaces');

// --- the demo front end (dev/templates): every page that shows the plugin's data renders with it
echo "Front end\n";
foreach ([
    '/' => 'Where we travel most',
    '/team' => 'Stories per writer',
    '/destinations/hong-kong' => 'Stories from here',
    '/destinations/hong-kong/p2' => 'Page 2 of', // Craft's page trigger
    '/journal?destination=taipei' => 'Stories from Taipei',
    '/journal/how-to-read-a-station' => 'Linked from',
    '/team/mara-lindqvist' => 'Stories by Mara',
    '/de/' => 'Where we travel most',
    '/destinations/taipei' => '.usesElement(entry)', // the inline code of the plugin hints
] as $path => $text) {
    [$status, $page] = $http->page($path);
    check($status === 200 && str_contains($page, $text), "front end $path shows “{$text}” (HTTP $status)");
}

// --- what the field's list shows: Craft's element index endpoint over the Usage type
echo "Usage index endpoint (the field's list)\n";
/** The usage list's response: rows, and the element actions Craft offers on them (Pro: "Replace…"). */
$usageIndex = function (craft\base\ElementInterface $of) use ($http): array {
    [$status, $body, $json] = $http->action('element-indexes/get-elements', [
        'elementType' => Usage::class,
        'context' => 'embedded-index',
        'source' => '*',
        'criteria' => ['usesElement' => $of->id, 'allSites' => 1, 'siteId' => $of->siteId],
        'viewState' => ['mode' => 'table', 'static' => false],
    ]);
    check($status === 200 && isset($json['html']), "get-elements answers (HTTP $status: " . substr($body, 0, 300) . ')');
    return $json ?? [];
};
$listUsages = function (craft\base\ElementInterface $of) use ($usageIndex): array {
    $json = $usageIndex($of);
    if (getenv('E2E_DEBUG')) {
        file_put_contents(getenv('E2E_DEBUG') . '-' . $of->id . '-' . ($GLOBALS['e2eDebugN'] = ($GLOBALS['e2eDebugN'] ?? 0) + 1) . '.html', $json['html']);
    }
    // table rows carry the element id the modal submits: <tr data-id="…" data-title="…">
    preg_match_all('/<tr data-id="(\d+)" data-title="([^"]*)"/', $json['html'] ?? '', $m, PREG_SET_ORDER);
    $rows = [];
    foreach ($m as $row) {
        $rows[(int)$row[1]] = html_entity_decode($row[2]);
    }
    return $rows;
};
$rows = $listUsages($from);
check(count($rows) === count($sources), 'index lists exactly the ' . count($sources) . ' sources (' . implode(', ', $rows) . ')');
foreach ($sources as $kind => $source) {
    check(isset($rows[$source->id]), "$kind source is listed under its own (owner) id");
}

// --- Craft's own filter rules and sort options of the types in the list, through the index endpoint
[$status, $body, $json] = $http->action('element-indexes/get-elements', [
    'elementType' => Usage::class, 'context' => 'embedded-index', 'source' => '*',
    'criteria' => ['usesElement' => $from->id, 'allSites' => 1, 'siteId' => $from->siteId],
    'condition' => ['class' => internetztube\elementRelations\elements\conditions\UsageCondition::class, 'elementType' => Usage::class, 'conditionRules' => [
        ['class' => craft\elements\conditions\entries\SectionConditionRule::class, 'operator' => 'in', 'values' => [$section->uid]],
    ]],
    'viewState' => ['mode' => 'table', 'static' => false, 'order' => 'postDate', 'sort' => 'desc'],
]);
preg_match_all('/<tr data-id="(\d+)"/', $json['html'] ?? '', $m);
check($status === 200 && count(array_unique($m[1])) === count($rows), "index: Craft's Section rule and sorting by Post Date work on the list (HTTP $status: " . substr($body, 0, 160) . ')');
[$status, , $json] = $http->action('element-indexes/get-elements', [
    'elementType' => Usage::class, 'context' => 'embedded-index', 'source' => '*',
    'criteria' => ['usesElement' => $from->id, 'allSites' => 1, 'siteId' => $from->siteId],
    'condition' => ['class' => internetztube\elementRelations\elements\conditions\UsageCondition::class, 'elementType' => Usage::class, 'conditionRules' => [
        ['class' => craft\elements\conditions\entries\SectionConditionRule::class, 'operator' => 'in', 'values' => [Craft::$app->getEntries()->getSectionByHandle('journal')->uid]],
    ]],
    'viewState' => ['mode' => 'table', 'static' => false],
]);
check($status === 200 && !str_contains($json['html'] ?? '', '<tr data-id='), "index: a Section rule for another section matches nothing (HTTP $status)");

// --- the index must never serve stale results: Craft caches index queries per element type, and these lists
// change when *other* elements are saved
echo "Index freshness (Craft's index query cache)\n";
$listUsages($from); // warm Craft's cache, if it were used
$renamed = $sources['field'];
$renamed->title = 'E2E source (field, renamed)';
$elements->saveElement($renamed) or throw new RuntimeException(json_encode($renamed->getErrors()));
check(($listUsages($from)[$renamed->id] ?? null) === 'E2E source (field, renamed)', 'renaming a source shows up in the list immediately');
$renamed->enabled = false;
$elements->saveElement($renamed) or throw new RuntimeException(json_encode($renamed->getErrors()));
[$status, , $json] = $http->action('element-indexes/get-elements', [
    'elementType' => Usage::class, 'context' => 'modal', 'source' => '*',
    'criteria' => ['usesElement' => $from->id, 'allSites' => 1, 'siteId' => $from->siteId, 'status' => 'disabled'], 'viewState' => ['mode' => 'table'],
]);
check($status === 200 && str_contains($json['html'] ?? '', 'data-id="' . $renamed->id . '"'), 'disabling a source shows up under the Disabled status immediately');
$renamed->enabled = true;
$renamed->title = 'E2E source (field)';
$elements->saveElement($renamed) or throw new RuntimeException(json_encode($renamed->getErrors()));
$sources['field'] = $renamed;
$flush();

// Pro "Used anywhere" filter on the asset index: an asset becomes unused when another element drops it.
// The filter goes along with Craft's index request as a condition config, on top of the volume's own source.
$testVolume = Craft::$app->getVolumes()->getVolumeByHandle('test');
$unusedFilter = [
    'class' => craft\elements\conditions\assets\AssetCondition::class,
    'elementType' => craft\elements\Asset::class,
    'fieldContext' => 'global',
    'conditionRules' => [['class' => internetztube\elementRelations\elements\conditions\UsedConditionRule::class, 'value' => false]],
];
$assetIndexIds = function (?array $filter) use ($http, $testVolume): array {
    // the parameters the asset index sends (captured from Craft.elementIndex.getViewParams() in the browser)
    [$status, , $json] = $http->action('element-indexes/get-elements', [
        'context' => 'index', 'elementType' => craft\elements\Asset::class, 'canHaveDrafts' => false, 'source' => "volume:$testVolume->uid", 'condition' => null,
        'baseCriteria' => ['status' => null, 'drafts' => false, 'draftOf' => false, 'savedDraftsOnly' => true, 'siteId' => Craft::$app->getSites()->getPrimarySite()->id],
        'criteria' => ['offset' => 0, 'limit' => 100, 'search' => null],
        'filterConfig' => $filter,
        'disabledElementIds' => [],
        'viewState' => ['mode' => 'table', 'showHeaderColumn' => true, 'inlineEditing' => false, 'static' => false, 'order' => 'dateUpdated', 'sort' => 'desc'],
        'paginated' => true, 'selectable' => true, 'sortable' => false, 'showFolders' => true, 'foldersOnly' => false,
    ]);
    check($status === 200 && isset($json['html']), 'the asset index answers' . ($filter ? ' with the “Used anywhere” filter' : '') . " (HTTP $status: " . substr(json_encode($json), 0, 300) . ')');
    preg_match_all('/data-id="(\d+)"/', $json['html'] ?? '', $m);
    return array_map('intval', array_unique($m[1]));
};
[$status, $assetsPage] = $http->page('/admin/assets');
check($status === 200 && !str_contains($assetsPage, 'data-key="elementRelations:unused"'), 'no extra “Unused” source in the asset sidebar');
$testAssetId = craft\elements\Asset::find()->volume('test')->orderBy(['elements.id' => SORT_ASC])->one()->id; // the seeded, used test asset
check(in_array($testAssetId, $assetIndexIds(null), true), 'the volume lists the test asset');
check(!in_array($testAssetId, $assetIndexIds($unusedFilter), true), 'the “Used anywhere: off” filter leaves it out (it is used)');
$probeAsset = (function () use ($elements) {
    $any = craft\elements\Asset::find()->volume('test')->one();
    $file = tempnam(sys_get_temp_dir(), 'er') . '.png';
    file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $asset = new craft\elements\Asset(['tempFilePath' => $file, 'filename' => 'rt.png', 'title' => 'E2E unused probe', 'newFolderId' => $any->folderId, 'volumeId' => $any->volumeId, 'avoidFilenameConflicts' => true]);
    $asset->setScenario(craft\elements\Asset::SCENARIO_CREATE);
    $elements->saveElement($asset) or throw new RuntimeException(json_encode($asset->getErrors()));
    return $asset;
})();
$unusedIds = $assetIndexIds($unusedFilter);
check(in_array($probeAsset->id, $unusedIds, true), "a new asset passes the “Used anywhere: off” filter (#{$probeAsset->id}; listed: " . implode(',', $unusedIds) . ')');
$user = $make('E2E source (asset user)');
$assetsField = Craft::$app->getFields()->getFieldByHandle('relatedAssets');
check($assetsField instanceof craft\fields\Assets, 'the seed added the Related Assets field');
if ($assetsField) {
    $user->setFieldValue($assetsField->handle, [$probeAsset->id]);
    $elements->saveElement($user) or throw new RuntimeException(json_encode($user->getErrors()));
    $flush();
    check(!in_array($probeAsset->id, $assetIndexIds($unusedFilter), true), 'once an entry uses it, the filter drops it immediately');
    $user->setFieldValue($assetsField->handle, []);
    $elements->saveElement($user) or throw new RuntimeException(json_encode($user->getErrors()));
    $flush();
    check(in_array($probeAsset->id, $assetIndexIds($unusedFilter), true), 'when the entry drops it, the filter lists it again immediately');
}
$elements->deleteElement($user, true);
$elements->deleteElement($probeAsset, true);

// an entry index sorted by the field (most used first): the order follows the usages at once, also when they change
// without a save of an entry (a queued refresh, a rebuild, a relating element of another type), which Craft's index
// query cache never hears of. Here a cache row moves from one entry to the other, as a refresh would move it.
$first = $make('E2E sort: first');
$second = $make('E2E sort: second');
$sorter = $make('E2E sort: user');
$sorter->setFieldValue('relatedEntries', [$first->id]);
$elements->saveElement($sorter) or throw new RuntimeException(json_encode($sorter->getErrors()));
$flush();
$sortedIds = function () use ($http, $first, $second): array {
    [, , $json] = $http->action('element-indexes/get-elements', [
        'context' => 'index', 'elementType' => Entry::class, 'source' => 'section:' . $first->getSection()->uid,
        'criteria' => ['id' => [$first->id, $second->id], 'siteId' => $first->siteId, 'status' => null, 'limit' => 10],
        'viewState' => ['mode' => 'table', 'showHeaderColumn' => true, 'inlineEditing' => false, 'static' => false, 'order' => 'field:' . Craft::$app->getFields()->getFieldByHandle('relations')->uid, 'sort' => 'desc'],
        'paginated' => true, 'selectable' => true, 'sortable' => false,
    ]);
    preg_match_all('/data-id="(\d+)"/', $json['html'] ?? '', $m);
    return array_values(array_intersect(array_map('intval', array_unique($m[1])), [$first->id, $second->id]));
};
check($sortedIds() === [$first->id, $second->id], 'an entry index sorted by the field: the used entry first');
Craft::$app->getDb()->createCommand()->update('{{%elementrelations_cache}}', ['targetElementId' => $second->id], ['targetElementId' => $first->id, 'sourceElementId' => $sorter->id])->execute();
check($sortedIds() === [$second->id, $first->id], 'the usage moved to the other entry without an entry being saved: the order follows at once (no stale index cache)');
foreach ([$sorter, $first, $second] as $entry) {
    $elements->deleteElement($entry, true);
}

// --- replacements and rollbacks run as queue jobs: post like the browser, then run exactly that job (as Craft's queue
// would; other queued work, like the save-event refreshes, stays queued for the checks below) and ask for its status
// like the field's script does
/** Runs the newest queued job whose description starts like this, exactly as Craft's queue would (with a job id). */
$runQueuedJob = function (string $descriptionStart): void {
    $id = (new craft\db\Query())->select(['id'])->from(craft\db\Table::QUEUE)
        ->where(['like', 'description', $descriptionStart . '%', false])
        ->andWhere(['fail' => false])
        ->orderBy(['id' => SORT_DESC])
        ->scalar();
    check($id !== false && $id !== null, "a queued job “{$descriptionStart}…”");
    if ($id) {
        Craft::$app->getQueue()->executeJob((string)$id);
    }
};
$runReplaceJob = fn(int $operationId) => $runQueuedJob(Craft::t('element-relations', 'replace-job-description'));
$runRollbackJob = fn(int $operationId, string $mode = 'swap') => $runQueuedJob(Craft::t('element-relations', 'rollback-job-description'));
/** @return array{int, string, array<string, mixed>} HTTP status, body, the status merged with the stored result */
$finished = function (int $operationId) use ($http): array {
    [$status, $body, $json] = $http->action('element-relations/replace/status', ['operationId' => $operationId]);
    $record = internetztube\elementRelations\records\ReplacementRecord::findOne($operationId);
    $result = $record?->getResult() ?? [];
    $merged = ($json ?? []) + $result + ['skipped' => [], 'restored' => $result['restored'] ?? 0];
    $merged['skipped'] = $record?->status === 'rolledBack' ? ($result['rollbackSkipped'] ?? []) : ($result['skipped'] ?? []);
    return [$status, $body, $merged];
};
$replaceAndRun = function (array $params) use ($http, $runReplaceJob, $finished): array {
    [$status, $body, $json] = $http->action('element-relations/replace/replace', $params);
    if ($status !== 200 || empty($json['operationId'])) {
        return [$status, $body, $json ?? []];
    }
    check(($json['queued'] ?? false) === true, 'the replacement is queued (operation #' . $json['operationId'] . ')');
    // a batched job: each run does one batch and queues the next
    for ($batch = 0; $batch < 20 && !($finished((int)$json['operationId'])[2]['done'] ?? false); $batch++) {
        $runReplaceJob((int)$json['operationId']);
    }
    return $finished((int)$json['operationId']);
};
$rollbackAndRun = function (int $operationId, string $mode = 'swap') use ($http, $runRollbackJob, $finished): array {
    [$status, $body, $json] = $http->action('element-relations/replace/rollback', ['operationId' => $operationId] + ($mode === 'swap' ? [] : ['mode' => $mode]));
    if ($status !== 200) {
        return [$status, $body, $json ?? []];
    }
    for ($batch = 0; $batch < 20 && !($finished($operationId)[2]['done'] ?? false); $batch++) {
        $runRollbackJob($operationId, $mode);
    }
    return $finished($operationId);
};

// --- "Replace…" in the usage list: an element action, so Craft shows checkboxes and its action bar
echo "Replace… action\n";
$offersReplace = fn(array $index): bool => in_array(internetztube\elementRelations\elements\actions\ReplaceUsages::class, array_column($index['actions'] ?? [], 'type'), true);
$index = $usageIndex($from);
check($offersReplace($index), 'Pro: the usage list offers "Replace…" (actions: ' . implode(', ', array_column($index['actions'] ?? [], 'type')) . ')');
check(str_contains($index['actionsBodyHtml'] ?? '', 'ElementRelationsReplace'), 'its trigger script comes with the index response');

// --- the wizard it opens (a slideout, here opened as a page): the replacement picker, the ticked usages, a review
echo "Wizard\n";
$tickedIds = array_values(array_unique(array_map(fn($e) => $e->id, $sources)));
[$status, $wizardHtml] = $http->page('/admin/actions/element-relations/replace/wizard?' . http_build_query(['fromId' => $from->id, 'siteId' => $from->siteId, 'sourceIds' => $tickedIds]));
preg_match_all('/<input[^>]*\bdata-usage\b/', $wizardHtml, $usageBoxes);
check($status === 200 && str_contains($wizardHtml, 'name="toId'), "the wizard renders with the replacement picker (HTTP $status)");
check(count($usageBoxes[0]) === count($tickedIds), 'it lists the ticked usages, one checkbox each (' . count($usageBoxes[0]) . ')');
check(str_contains($wizardHtml, 'data-summary'), 'with a review');
[, $wizardHtml] = $http->page('/admin/actions/element-relations/replace/wizard?' . http_build_query(['fromId' => $from->id, 'siteId' => $from->siteId, 'sourceIds' => [$tickedIds[0]], 'criteria' => '{}']));
check(str_contains($wizardHtml, 'value="list"') === (count($tickedIds) > 1), 'one ticked of several: it offers all usages in the list (other pages)');
[$status, $wizardHtml] = $http->page('/admin/actions/element-relations/replace/wizard?' . http_build_query(['fromId' => $from->id, 'siteId' => $from->siteId, 'scope' => 'everywhere']));
check($status === 200 && !preg_match('/<input[^>]*\bdata-usage\b/', $wizardHtml) && str_contains($wizardHtml, 'name="scope" value="everywhere"'), '"Replace everywhere…" opens it for all usages, without a list');

// --- the action posts the ticked ids to the replace endpoint
echo "Replace endpoint\n";
$ticked = [$sources['field']->id];
if (isset($sources['nested'])) {
    $ticked[] = $sources['nested']->id;
}
[$status, $body, $json] = $replaceAndRun(['fromId' => $from->id, 'toId' => $target->id, 'siteId' => $from->siteId, 'sourceIds' => $ticked]);
check($status === 200 && ($json['done'] ?? false) && !empty($json['message']) && ($json['canUndo'] ?? false), "replace succeeds for the ticked usages; the status says done and offers Undo (HTTP $status: " . substr($body, 0, 200) . ')');
check(($json['replaced'] ?? 0) === count($ticked) && empty($json['skipped']), 'exactly the ticked elements were rewritten, nothing skipped (' . substr($body, 0, 200) . ')');
$flush();
check(!in_array('E2E source (field)', $relating($from), true) && in_array('E2E source (field)', $relating($target), true), 'the entries-field source now relates to the target');
if (isset($sources['nested'])) {
    check(in_array('E2E source (nested)', $relating($target), true), 'the nested (Matrix) source now relates to the target');
}
if (isset($sources['ckeditor'])) {
    check(in_array('E2E source (ckeditor)', $relating($from), true), 'the unticked CKEditor source still relates to "E2E from"');
}
$rowsAfter = $listUsages($target);
check(count($rowsAfter) === count($ticked), 'the index of the target now lists the replaced sources (' . implode(', ', $rowsAfter) . ')');

[$status, , $json] = $http->action('element-relations/replace/replace', ['fromId' => $from->id, 'toId' => $target->id, 'siteId' => $from->siteId, 'sourceIds' => $ticked]);
check($status === 400 && str_contains($json['message'] ?? '', 'anymore'), 'replacing the same usages again fails with a clear message');
[$status, , $json] = $http->action('element-relations/replace/replace', ['fromId' => $from->id, 'toId' => $from->id, 'siteId' => $from->siteId, 'sourceIds' => $ticked]);
check($status === 400, 'replacing an element by itself is rejected');

// --- "Replace everywhere…" on the entry index: its trigger posts no ids, just the scope
echo "Replace everywhere…\n";
[$status, $body, $json] = $replaceAndRun(['fromId' => $target->id, 'toId' => $from->id, 'siteId' => $from->siteId, 'scope' => 'everywhere']);
check($status === 200 && ($json['done'] ?? false) && ($json['replaced'] ?? 0) === count($ticked), "replace everywhere is queued and rewrites every usage (HTTP $status: " . substr($body, 0, 200) . ')');
$flush();

// --- "All … in the list": the list's filters decide, resolved on the server (here: only the entries)
echo "All in the list\n";
[$status] = $http->action('element-relations/replace/replace', ['fromId' => $from->id, 'toId' => $target->id, 'siteId' => $from->siteId, 'scope' => 'list', 'criteria' => json_encode(['elementTypes' => craft\elements\Asset::class])]);
check($status === 400, "a list filtered to assets has none of these usages: nothing to replace (HTTP $status)");
[$status, $body, $json] = $replaceAndRun(['fromId' => $from->id, 'toId' => $target->id, 'siteId' => $from->siteId, 'scope' => 'list', 'criteria' => json_encode(['elementTypes' => Entry::class, 'usesElement' => 999999])]);
check($status === 200 && ($json['done'] ?? false) && ($json['replaced'] ?? 0) === count($tickedIds), "all in the list filtered to entries: every usage, across pages; a smuggled usesElement is ignored (HTTP $status: " . substr($body, 0, 200) . ')');
[$status] = $rollbackAndRun((int)($json['operationId'] ?? 0));
$flush();
check($status === 200 && count($listUsages($from)) === count($tickedIds), 'rolled back: everything is listed under "E2E from" again');
check(count(array_intersect(array_map('strval', $sources), $relating($from))) === count($sources), 'everything points back to "E2E from" after replacing everywhere');

// --- across pages: the demo's station notes image has 90 usages (pages of 20 in the field's list)
echo "Across pages (demo: station notes)\n";
$notes = craft\elements\Asset::find()->filename('station-notes.jpg')->one();
$notesTo = craft\elements\Asset::find()->filename('waiting-on-the-platform.jpg')->one();
if ($notes && $notesTo) {
    $firstPage = array_slice(array_keys($listUsages($notes)), 0, 20);
    $all = count((new internetztube\elementRelations\models\RelationsModel($notes->id, $notes->siteId))->getSourceElementIds());
    [$status, $wizardHtml] = $http->page('/admin/actions/element-relations/replace/wizard?' . http_build_query(['fromId' => $notes->id, 'siteId' => $notes->siteId, 'sourceIds' => $firstPage, 'criteria' => '{}']));
    check($status === 200 && str_contains($wizardHtml, 'value="list"') && str_contains($wizardHtml, "All $all elements in the list, ticked or not"), "a ticked page of 20: the wizard offers all $all elements in the list");
    check(preg_match('/value="list" data-scope>/', $wizardHtml) === 1 && str_contains($wizardHtml, 'The ' . count($firstPage) . ' elements you ticked') && str_contains($wizardHtml, "All $all elements in the list, ticked or not"), 'the ticked ones are preselected; both options count elements and usages (an element in one site) exactly');
    [, $wizardHtml] = $http->page('/admin/actions/element-relations/replace/wizard?' . http_build_query(['fromId' => $notes->id, 'siteId' => $notes->siteId, 'sourceIds' => $firstPage, 'criteria' => '{}', 'allTicked' => 1]));
    check(preg_match('/value="list" data-scope checked>/', $wizardHtml) === 1, 'the whole page ticked: "all in the list" is preselected');
    if (Craft::$app->getIsMultiSite()) {
        $siteName = (string)$notes->getSite()->getName();
        check(preg_match('/name="sites" value="current" data-sites checked>/', $wizardHtml) === 1 && str_contains($wizardHtml, "Only in $siteName") && str_contains($wizardHtml, 'In all sites ('), "the usages reach other sites: it asks “Only in {$siteName}” (preselected) or “In all sites”");
        check(str_contains($wizardHtml, 'data-changes') && str_contains($wizardHtml, '"changesList":{"here":') && str_contains($wizardHtml, '"changesEverywhere":{"here":'), 'and carries the review\'s "What changes": elements per site for both choices, and the ones that change in other sites as well');
    }
    [$status, $body, $json] = $replaceAndRun(['fromId' => $notes->id, 'toId' => $notesTo->id, 'siteId' => $notes->siteId, 'scope' => 'list', 'criteria' => '{}']);
    check($status === 200 && ($json['replaced'] ?? 0) === $all && $all > 20, "all in the list: $all replaced, not just the ticked page (" . substr($body, 0, 160) . ')');
    [$status] = $rollbackAndRun((int)($json['operationId'] ?? 0));
    $flush();
    check($status === 200 && count((new internetztube\elementRelations\models\RelationsModel($notes->id, $notes->siteId))->getSourceElementIds()) === $all, "rolled back: the $all use the station notes image again");
} else {
    check(false, 'the demo has the station notes image (run the seed)');
}

// --- every seeded usage through the action's path, one row at a time
echo "Every usage through Replace… (Page C and the test asset)\n";
// sorted in PHP: rows that tie on some columns (e.g. global usages per site) come back in any order on PostgreSQL
$cacheFingerprint = function (): string {
    $rows = array_map('json_encode', (new craft\db\Query())
        ->select(['sourceElementId', 'sourceSiteId', 'sourcePrimaryOwnerId', 'targetElementId', 'targetSiteId', 'fieldId', 'type', 'globalKey'])
        ->from('{{%elementrelations_cache}}')
        ->all());
    sort($rows);
    return md5(implode("\n", $rows));
};
$runQueue = function (): void {
    Craft::$app->getQueue()->run(); // the web requests queued their save-event refreshes
};
$runQueue();
$fingerprintAtStart = $cacheFingerprint();
$pageC = Entry::find()->title('Page C')->one() ?? throw new RuntimeException('Run the seed first');
$testAsset = craft\elements\Asset::find()->volume('test')->orderBy(['elements.id' => SORT_ASC])->one();
$tmpAsset = function () use ($testAsset, $elements): craft\elements\Asset {
    $file = tempnam(sys_get_temp_dir(), 'er') . '.png';
    file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $asset = new craft\elements\Asset(['tempFilePath' => $file, 'filename' => 'rt.png', 'title' => 'E2E asset target', 'newFolderId' => $testAsset->folderId, 'volumeId' => $testAsset->volumeId, 'avoidFilenameConflicts' => true]);
    $asset->setScenario(craft\elements\Asset::SCENARIO_CREATE);
    $elements->saveElement($asset) or throw new RuntimeException(json_encode($asset->getErrors()));
    return $asset;
};
$cases = [[$pageC, $make('E2E entry target')], [$testAsset, $tmpAsset()]];
$checked = 0;
foreach ($cases as [$origin, $replacement]) {
    $originalRows = $listUsages($origin);
    check(count($originalRows) > 5, "$origin: the index lists its usages (" . count($originalRows) . ')');
    foreach ($originalRows as $ownerId => $title) {
        if (($only = getenv('E2E_ONLY')) && !str_contains($title, $only)) { // debugging: E2E_ONLY="Admin wishlist"
            continue;
        }
        $what = "$origin in “{$title}”";
        // forward, exactly as the action posts it
        [$status, $body, $json] = $replaceAndRun(['fromId' => $origin->id, 'toId' => $replacement->id, 'siteId' => $origin->siteId, 'sourceIds' => [$ownerId]]);
        check($status === 200 && ($json['replaced'] ?? 0) >= 1 && empty($json['skipped']), "$what: replaced, nothing skipped (HTTP $status: " . substr($body, 0, 220) . ')');
        $fromRows = $listUsages($origin);
        $toRows = $listUsages($replacement);
        check(!isset($fromRows[$ownerId]), "$what: gone from the list right after the job (without the save-event refreshes; still listed: " . json_encode((new craft\db\Query())->select(['sourceElementId', 'sourceSiteId', 'type', 'fieldId'])->from('{{%elementrelations_cache}}')->where(['targetElementId' => $origin->id, 'sourcePrimaryOwnerId' => $ownerId])->all()) . ')');
        check(isset($toRows[$ownerId]), "$what: listed under the replacement right after the job");
        check(array_diff_key($originalRows, [$ownerId => 1]) == $fromRows, "$what: every other usage is untouched");
        $before = $cacheFingerprint();
        $runQueue();
        check($cacheFingerprint() === $before, "$what: the queued refresh changes nothing (cache was already correct)");
        $owner = Craft::$app->getElements()->getElementById($ownerId, null, null, ['status' => null]);
        if ($owner && ($url = $owner->getCpEditUrl())) {
            [$status] = $http->page(parse_url($url, PHP_URL_PATH) . '?' . (parse_url($url, PHP_URL_QUERY) ?? ''));
            check($status === 200, "$what: the source's edit page still renders (HTTP $status)");
        }
        // and undo, exactly as the notice's Undo button does it
        check(!empty($json['operationId']), "$what: the response carries the operation id for Undo");
        $operationId = $json['operationId'];
        [$status, $body, $json] = $rollbackAndRun((int)$operationId);
        check($status === 200 && ($json['status'] ?? '') === 'rolledBack' && ($json['restored'] ?? 0) >= 1 && empty($json['skipped']), "$what: Undo rolls it back (HTTP $status: " . substr($body, 0, 220) . ')');
        check($listUsages($origin) == $originalRows && !$listUsages($replacement), "$what: back to the original list");
        $before = $cacheFingerprint();
        $runQueue();
        check($cacheFingerprint() === $before, "$what: the queued refresh after the rollback changes nothing");
        [$status, , $json] = $http->action('element-relations/replace/rollback', ['operationId' => $operationId]);
        check($status === 400, "$what: a second Undo is refused");
        $checked++;
    }
    $elements->deleteElement($replacement, true);
}
check($cacheFingerprint() === $fingerprintAtStart || getenv('E2E_ONLY'), "the relations cache is byte-identical to the start after $checked round trips");
$failedJobs = (new craft\db\Query())->from('{{%queue}}')->where(['fail' => true])->count();
check((int)$failedJobs === 0, 'no failed queue jobs');

// --- the history in Utilities: lists operations, rolls back through a plain form post
echo "Replacement history (Utilities)\n";
$historyFrom = $make('E2E history from');
$historyTo = $make('E2E history to');
$historySource = $make('E2E history source', ['relatedEntries' => [$historyFrom->id]]);
$flush();
[$status, , $json] = $replaceAndRun(['fromId' => $historyFrom->id, 'toId' => $historyTo->id, 'siteId' => $historyFrom->siteId, 'sourceIds' => [$historySource->id]]);
check($status === 200 && !empty($json['operationId']), 'a replacement for the history');
$operationId = $json['operationId'];
[$status, $html] = $http->page('/admin/utilities/element-relations');
check($status === 200 && str_contains($html, 'id="element-relations-history"'), 'the utility renders the history table');
$jobsBefore = (int)(new craft\db\Query())->from(craft\db\Table::QUEUE)->count();
[$status, $rebuildHtml] = $http->request('POST', '/admin/utilities/element-relations', [], false);
$jobsAfter = (int)(new craft\db\Query())->from(craft\db\Table::QUEUE)->count();
check($status === 200 && $jobsAfter === $jobsBefore + 1 && str_contains($rebuildHtml, json_encode(Craft::t('element-relations', 'utility-form-rebuild-cache-success-notice'))), "the utility's button queues the full rebuild and says so (HTTP $status)");
$flush();
[$status, $settingsHtml] = $http->page('/admin/settings/plugins/element-relations');
check($status === 200 && str_contains($settingsHtml, 'bulkRefreshBatchSize'), "the plugin's settings page shows its settings (HTTP $status)");
check(str_contains($settingsHtml, 'name="settings[protectInUse]"'), 'Pro: the settings page has the “Protect elements in use” switch');
check(preg_match('#<tr data-operation-id="' . $operationId . '">(.*?)</tr>#s', $html, $row) === 1, 'the operation is listed');
check(str_contains($row[1], 'E2E history from') && str_contains($row[1], 'E2E history to') && str_contains($row[1], 'admin'), 'its row shows what was replaced with what, and by whom');
check(str_contains($row[1], 'element-relations/replace/rollback') && str_contains($row[1], 'name="operationId" value="' . $operationId . '"'), 'its row has a Roll back form');
// submit it like the browser would: form-encoded POST with CSRF and redirect
preg_match('/name="CRAFT_CSRF_TOKEN" value="([^"]+)"/', $row[1], $token);
preg_match('/name="redirect" value="([^"]+)"/', $row[1], $redirect);
$ch = curl_init($base . '/index.php?p=admin/actions/element-relations/replace/rollback');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_USERAGENT => 'element-relations-e2e', CURLOPT_COOKIEFILE => $http->jar, CURLOPT_COOKIEJAR => $http->jar, CURLOPT_POSTFIELDS => http_build_query(['CRAFT_CSRF_TOKEN' => html_entity_decode($token[1] ?? ''), 'redirect' => html_entity_decode($redirect[1] ?? ''), 'operationId' => $operationId])]);
curl_exec($ch);
$formStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
check($formStatus === 302, "the Roll back form redirects back to the utility (HTTP $formStatus)");
check(internetztube\elementRelations\records\ReplacementRecord::findOne($operationId)?->status === 'rollbackQueued', 'the form queued the rollback');
$runRollbackJob((int)$operationId);
$flush();
check(in_array('E2E history source', $relating($historyFrom), true) && !in_array('E2E history source', $relating($historyTo), true), 'the form rolled the replacement back');
[, $html] = $http->page('/admin/utilities/element-relations');
preg_match('#<tr data-operation-id="' . $operationId . '">(.*?)</tr>#s', $html, $row);
check(!str_contains($row[1] ?? '', 'element-relations/replace/rollback') && str_contains($row[1] ?? '', 'Rolled back'), 'afterwards the row says Rolled back and offers no button');
// revert to revisions through the endpoint (the history's second button posts mode=revisions after a confirm);
// no pauses on purpose: everything may happen within one second, revisions must still be right
[$status, , $json] = $replaceAndRun(['fromId' => $historyFrom->id, 'toId' => $historyTo->id, 'siteId' => $historyFrom->siteId, 'sourceIds' => [$historySource->id]]);
check($status === 200 && !empty($json['operationId']), 'a second replacement for the revert');
$revertId = $json['operationId'];
[, $html] = $http->page('/admin/utilities/element-relations');
preg_match('#<tr data-operation-id="' . $revertId . '">(.*?)</tr>#s', $html, $row);
check(str_contains($row[1] ?? '', 'name="mode" value="revisions"') && str_contains($row[1] ?? '', 'confirm('), 'the history offers "Revert to revisions" (with a confirmation) for a versioned entry');
$later = Entry::find()->id($historySource->id)->one();
$later->title = 'E2E history source (edited later)';
$elements->saveElement($later) or throw new RuntimeException(json_encode($later->getErrors()));
$flush();
[$status, $body, $json] = $rollbackAndRun((int)$revertId, 'revisions');
check($status === 200 && ($json['reverted'] ?? 0) === 1, "revert to revisions reverts one entry (HTTP $status: " . substr($body, 0, 200) . ')');
$flush();
$back = Entry::find()->id($historySource->id)->one();
check((string)$back === 'E2E history source' && in_array('E2E history source', $relating($historyFrom), true), 'the entry is back at its revision: original relation, later title edit discarded');
foreach ([$historySource, $historyTo, $historyFrom] as $entry) {
    $elements->deleteElement($entry, true);
}

// --- a stuck replacement (its queue job is gone) is resumed by the field's status polling
echo "Stuck replacement\n";
$stuckFrom = $make('E2E stuck from');
$stuckTo = $make('E2E stuck to');
$stuckSource = $make('E2E stuck source', ['relatedEntries' => [$stuckFrom->id]]);
$flush();
[$status, , $json] = $http->action('element-relations/replace/replace', ['fromId' => $stuckFrom->id, 'toId' => $stuckTo->id, 'siteId' => $stuckFrom->siteId, 'sourceIds' => [$stuckSource->id]]);
$stuckId = (int)($json['operationId'] ?? 0);
check($status === 200 && $stuckId > 0, 'a queued replacement');
(new craft\db\Query())->createCommand()->delete(craft\db\Table::QUEUE, ['like', 'description', Craft::t('element-relations', 'replace-job-description') . '%', false])->execute();
internetztube\elementRelations\records\ReplacementRecord::findOne($stuckId)?->updateAttributes(['dateUpdated' => craft\helpers\Db::prepareDateForDb(new DateTime('-20 minutes'))]);
[$status, , $json] = $http->action('element-relations/replace/status', ['operationId' => $stuckId]);
check($status === 200 && ($json['done'] ?? true) === false, 'its job is gone and 20 minutes passed: the status says still running');
[, , $finishedJson] = (function () use ($runReplaceJob, $finished, $stuckId) {
    $runReplaceJob($stuckId); // the job the status request queued again
    return $finished($stuckId);
})();
check(($finishedJson['status'] ?? '') === 'applied' && in_array('E2E stuck source', $relating($stuckTo), true), 'the status request resumed it, and it finished');
foreach ([$stuckSource, $stuckTo, $stuckFrom] as $entry) {
    $elements->deleteElement($entry, true);
}

// --- Pro: elements in use can't be deleted or taken offline in the control panel without the permission
echo "In use: delete and disable guard\n";
// on the demo's journal articles: the test pages have a Formie "Forms" field, and Formie's query for its empty value
// fails on PostgreSQL when a non-admin saves (a Formie bug, unrelated to this check)
$journal = Craft::$app->getEntries()->getSectionByHandle('journal') ?? throw new RuntimeException('Run the seed first');
$articleTypeId = Craft::$app->getEntries()->getEntryTypeByHandle('article')->id;
$article = function (string $title, array $fields = [], bool $enabled = true) use ($journal, $articleTypeId, $elements): Entry {
    $entry = new Entry(['sectionId' => $journal->id, 'typeId' => $articleTypeId, 'title' => $title, 'enabled' => $enabled]);
    $entry->setFieldValues($fields);
    $elements->saveElement($entry) or throw new RuntimeException(json_encode($entry->getErrors()));
    return $entry;
};
foreach (Entry::find()->section('journal')->title('E2E *')->status(null)->all() as $old) {
    $elements->deleteElement($old, true);
}
$inUse = $article('E2E in use');
$article('E2E in use source', ['relatedArticles' => [$inUse->id]]);
// a second usage, in a section the editor may not view: counted, never named, the dialog's list says it left one out
foreach (Entry::find()->section('pages')->title('E2E hidden source')->status(null)->all() as $old) {
    $elements->deleteElement($old, true);
}
$pagesSection = Craft::$app->getEntries()->getSectionByHandle('pages') ?? throw new RuntimeException('Run the seed first');
$hiddenSource = new Entry(['sectionId' => $pagesSection->id, 'typeId' => $pagesSection->getEntryTypes()[0]->id, 'title' => 'E2E hidden source']);
$hiddenSource->setFieldValues(['relatedEntries' => [$inUse->id]]);
$elements->saveElement($hiddenSource) or throw new RuntimeException(json_encode($hiddenSource->getErrors()));
$notUsed = $article('E2E not in use');
$byDisabledOnly = $article('E2E used by a disabled entry only');
$article('E2E disabled source', ['relatedArticles' => [$byDisabledOnly->id]], false);
$flush();
// a section whose entries choose their sites, for “Delete for site” (before the first permission save: Craft knows
// the permissions of the sections that existed then)
$entriesService = Craft::$app->getEntries();
$perSiteSection = $entriesService->getSectionByHandle('e2ePerSite') ?? new craft\models\Section([
    'name' => 'E2E per site', 'handle' => 'e2ePerSite', 'type' => craft\models\Section::TYPE_CHANNEL, 'propagationMethod' => craft\enums\PropagationMethod::Custom,
    'siteSettings' => array_map(fn($site) => new craft\models\Section_SiteSettings(['siteId' => $site->id, 'hasUrls' => false]), Craft::$app->getSites()->getAllSites()),
]);
if (!$perSiteSection->id) {
    $perSiteSection->setEntryTypes([$entriesService->getEntryTypeById($articleTypeId)]);
    $entriesService->saveSection($perSiteSection) or throw new RuntimeException(json_encode($perSiteSection->getErrors()));
    Craft::$app->getProjectConfig()->saveModifiedConfigData(); // for the web process
}
// an editor who may edit and delete these entries, but not elements in use
foreach (craft\elements\User::find()->username('e2e-editor')->status(null)->all() as $old) {
    $elements->deleteElement($old, true);
}
$editorPassword = bin2hex(random_bytes(8)); // a throwaway test login for this run
$editor = new craft\elements\User(['username' => 'e2e-editor', 'email' => 'e2e-editor@example.test', 'newPassword' => $editorPassword, 'active' => true, 'pending' => false]);
$elements->saveElement($editor, false) or throw new RuntimeException(json_encode($editor->getErrors()));
$editorPermissions = [
    'accessCp',
    ...array_map(fn($site) => "editSite:$site->uid", Craft::$app->getSites()->getAllSites()),
    ...array_map(fn($p) => "$p:$journal->uid", ['viewEntries', 'createEntries', 'saveEntries', 'deleteEntries', 'viewPeerEntries', 'savePeerEntries', 'deletePeerEntries']),
    ...array_map(fn($p) => "$p:$perSiteSection->uid", ['viewEntries', 'saveEntries', 'deleteEntries', 'viewPeerEntries', 'savePeerEntries', 'deletePeerEntries', 'deleteEntriesForSite', 'deletePeerEntriesForSite']),
];
Craft::$app->getUserPermissions()->saveUserPermissions($editor->id, $editorPermissions);
$editorHttp = new Http($base);
[, , $info] = $editorHttp->action('users/session-info', [], 'GET');
$editorHttp->csrf = $info['csrfTokenValue'] ?? null;
[$status] = $editorHttp->action('users/login', ['loginName' => 'e2e-editor', 'password' => $editorPassword]);
[, , $info] = $editorHttp->action('users/session-info', [], 'GET');
$editorHttp->csrf = $info['csrfTokenValue'] ?? $editorHttp->csrf;
check($status === 200 && ($info['username'] ?? '') === 'e2e-editor', "the editor is logged in (HTTP $status)");
// Formie scopes its form query to the user's forms; for a user with none that was invalid SQL on PostgreSQL
$formTarget = Entry::find()->section('pages')->title('Formie Default Target')->one();
if ($formTarget && class_exists(verbb\formie\elements\Form::class)) {
    // for this check only: the test pages may be viewed (the entry a form uses), Formie itself not
    $pagesUid = Craft::$app->getEntries()->getSectionByHandle('pages')?->uid;
    Craft::$app->getUserPermissions()->saveUserPermissions($editor->id, [...$editorPermissions, "viewEntries:$pagesUid", "viewPeerEntries:$pagesUid"]);
    [$status, $body, $json] = $editorHttp->action('element-indexes/get-elements', [
        'elementType' => Usage::class, 'context' => 'embedded-index', 'source' => '*',
        'criteria' => ['usesElement' => $formTarget->id, 'allSites' => 1, 'siteId' => $formTarget->siteId],
        'viewState' => ['mode' => 'table', 'static' => false],
    ]);
    check($status === 200 && isset($json['html']), "an editor without access to Formie lists the usages of an entry a form uses (HTTP $status" . ($status === 200 ? '' : ': ' . substr($body, 0, 160)) . ')');
    Craft::$app->getUserPermissions()->saveUserPermissions($editor->id, $editorPermissions);
}
$stillThere = fn(Entry $entry): bool => (bool)Entry::find()->id($entry->id)->status(null)->exists();
$isLive = fn(Entry $entry): bool => Entry::find()->id($entry->id)->status(null)->one()?->getStatus() === Entry::STATUS_LIVE;

[$status, , $json] = $editorHttp->action('elements/delete', ['elementId' => $inUse->id, 'siteId' => $inUse->siteId]);
$errors = json_encode($json['errors'] ?? [], JSON_UNESCAPED_UNICODE);
check($status === 400 && str_contains($errors, 'can’t be deleted') && str_contains($errors, 'E2E in use source') && $stillThere($inUse), "edit page: deleting an element in use is refused, naming the usage (HTTP $status: " . substr($errors, 0, 200) . ')');
check(str_contains($errors, '2 live elements use it') && str_contains($errors, 'and one more') && !str_contains($errors, 'E2E hidden source'), "edit page: a usage the editor may not view is counted, not named ($errors)");
[$status, , $json] = $editorHttp->action('elements/save', ['elementId' => $inUse->id, 'siteId' => $inUse->siteId, 'enabled' => false]);
check($status === 400 && str_contains(json_encode($json['errors']['enabled'] ?? [], JSON_UNESCAPED_UNICODE), 'can’t be disabled') && $isLive($inUse), "edit page: disabling it is refused on the Enabled field (HTTP $status)");
[$status, , $json] = $editorHttp->action('elements/save', ['elementId' => $inUse->id, 'siteId' => $inUse->siteId, 'expiryDate' => ['date' => '2001-01-01', 'time' => '10:00 AM', 'timezone' => 'UTC']]);
check($status === 400 && str_contains(json_encode($json['errors']['expiryDate'] ?? [], JSON_UNESCAPED_UNICODE), 'can’t expire') && $isLive($inUse), "edit page: an expiry date in the past is refused (HTTP $status)");
[$status, , $json] = $editorHttp->action('elements/save', ['elementId' => $inUse->id, 'siteId' => $inUse->siteId, 'postDate' => ['date' => '2099-01-01', 'time' => '10:00 AM', 'timezone' => 'UTC']]);
check($status === 400 && str_contains(json_encode($json['errors']['postDate'] ?? [], JSON_UNESCAPED_UNICODE), 'can’t be scheduled') && $isLive($inUse), "edit page: scheduling it for later is refused (HTTP $status)");
[$status, , $json] = $editorHttp->action('elements/save', ['elementId' => $inUse->id, 'siteId' => $inUse->siteId, 'enabledForSite' => false]);
check($status === 400 && str_contains(json_encode($json['errors']['enabled'] ?? [], JSON_UNESCAPED_UNICODE), 'can’t be disabled for') && $isLive($inUse), "edit page: disabling it for this site is refused, naming the site (HTTP $status)");
$indexAction = fn(string $action, array $params, Entry $entry) => $editorHttp->action('element-indexes/perform-action', [
    'elementType' => Entry::class, 'context' => 'index', 'source' => "section:$journal->uid", 'elementAction' => $action,
    'elementIds' => [$entry->id], 'criteria' => ['siteId' => $entry->siteId, 'status' => null],
    'viewState' => ['mode' => 'table', 'static' => false],
] + $params);
[$status, , $json] = $indexAction(craft\elements\actions\Delete::class, [], $inUse);
check($status === 400 && str_contains($json['message'] ?? '', 'can’t be deleted') && $stillThere($inUse), "index: the Delete action is refused with the reason (HTTP $status: " . ($json['message'] ?? '') . ')');
[$status, , $json] = $indexAction(craft\elements\actions\SetStatus::class, ['status' => 'disabled'], $inUse);
check($status === 400 && str_contains($json['message'] ?? '', 'can’t be disabled') && $isLive($inUse), "index: Set status → Disabled is refused (HTTP $status)");
// Craft 5.10+'s delete dialog: the usages as a blocker, no way past it without the permission; its delete is refused
$dialog = fn(string $route) => $editorHttp->action("delete-elements/$route", ['elementType' => Entry::class, 'elementIds' => [$inUse->id], 'siteId' => $inUse->siteId]);
if (defined(craft\base\Element::class . '::EVENT_DEFINE_DELETION_BLOCKERS')) {
    [$status, , $json] = $dialog('deletion-blockers');
    $ours = array_values(array_filter($json['blockers'] ?? [], fn($b) => str_starts_with($b['summary'] ?? '', 'Element Relations found')));
    check($status === 200 && $ours && str_contains($ours[0]['summary'], 'E2E in use source') && !array_filter($ours[0]['actions'], fn($a) => ($a['label'] ?? '') === 'Delete anyway'), "delete dialog: the usages are a blocker, without “Delete anyway” for the editor (HTTP $status: " . ($ours[0]['summary'] ?? json_encode($json['blockers'] ?? [])) . ')');
    check(str_contains($ours[0]['details'] ?? '', 'Craft.createElementIndex') || str_contains($json['bodyHtml'] ?? '', 'usesElement'), 'delete dialog: it lists the usages');
    check(!str_contains($ours[0]['summary'], 'E2E hidden source') && str_contains($ours[0]['details'] ?? '', 'data-element-relations-hidden="1"'), "delete dialog: the usage the editor may not view is not named, and the list says it leaves one out ({$ours[0]['summary']})");
    [$status, , $json] = $dialog('delete');
    check(($json['showAsFailure'] ?? false) === true && $stillThere($inUse), "delete dialog: deleting anyway is refused (HTTP $status: " . ($json['message'] ?? '') . ')');
}
[$status] = $indexAction(craft\elements\actions\SetStatus::class, ['status' => 'enabled'], $inUse);
check($status === 200 && $isLive($inUse), "index: an action that takes nothing offline (Set status → Enabled) is not in the way (HTTP $status)");
[$status] = $indexAction(craft\elements\actions\SetStatus::class, ['status' => 'disabled'], $byDisabledOnly);
check($status === 200 && !$isLive($byDisabledOnly), "only live usages count: one used by a disabled entry can be disabled (HTTP $status)");
[$status] = $editorHttp->action('elements/delete', ['elementId' => $notUsed->id, 'siteId' => $notUsed->siteId]);
check($status === 200 && !$stillThere($notUsed), "an element nobody uses can be deleted (HTTP $status)");
$alsoNotUsed = $article('E2E not in use either');
[$status] = $indexAction(craft\elements\actions\Delete::class, [], $alsoNotUsed);
check($status === 200 && !$stillThere($alsoNotUsed), "index: the Delete action on elements nobody uses is not in the way (HTTP $status)");
// saves that keep it live, or bring it back, are never in the way
[$status, , $json] = $editorHttp->action('elements/save', ['elementId' => $inUse->id, 'siteId' => $inUse->siteId, 'title' => 'E2E in use']);
check($status === 200 && $isLive($inUse), "edit page: saving an element in use that stays live is not in the way (HTTP $status)");
$inUse->enabled = false;
$elements->saveElement($inUse); // the console is never guarded
[$status] = $editorHttp->action('elements/save', ['elementId' => $inUse->id, 'siteId' => $inUse->siteId, 'enabled' => true]);
check($status === 200 && $isLive($inUse), "edit page: enabling it again is not in the way (HTTP $status)");
// the edit page's sidebar switches off another site than the one being edited: that site is named
$allSites = Craft::$app->getSites()->getAllSites();
[$firstSite, $lastSite] = [$allSites[0], end($allSites)];
[$status, , $json] = $editorHttp->action('elements/save', ['elementId' => $inUse->id, 'siteId' => $lastSite->id, 'enabledForSite' => [$firstSite->id => false, $lastSite->id => true]]);
$errors = json_encode($json['errors']['enabled'] ?? [], JSON_UNESCAPED_UNICODE);
check($status === 400 && str_contains($errors, 'can’t be disabled for ' . $firstSite->getName()) && $isLive($inUse), "edit page in $lastSite->name: switching off $firstSite->name is refused, naming that site (HTTP $status: " . substr($errors, 0, 160) . ')');
// "Delete for site": a section whose entries choose their sites
$perSite = new Entry(['sectionId' => $perSiteSection->id, 'typeId' => $articleTypeId, 'title' => 'E2E per site', 'siteId' => $allSites[0]->id]);
$perSite->setEnabledForSite(array_fill_keys(array_map(fn($site) => (int)$site->id, $allSites), true));
$elements->saveElement($perSite) or throw new RuntimeException(json_encode($perSite->getErrors()));
$pagesSection = $entriesService->getSectionByHandle('pages');
$perSiteSource = new Entry(['sectionId' => $pagesSection->id, 'typeId' => $pagesSection->getEntryTypes()[0]->id, 'title' => 'E2E per site source', 'siteId' => $allSites[0]->id]);
$perSiteSource->setFieldValue('relatedEntries', [$perSite->id]);
$elements->saveElement($perSiteSource) or throw new RuntimeException(json_encode($perSiteSource->getErrors()));
$flush();
[$status, $body, $json] = $editorHttp->action('elements/delete-for-site', ['elementId' => $perSite->id, 'siteId' => $allSites[0]->id]);
$inSite = fn(): bool => (bool)Entry::find()->id($perSite->id)->siteId($allSites[0]->id)->status(null)->exists();
check($status === 400 && str_contains($body . json_encode($json, JSON_UNESCAPED_UNICODE), 'can’t be deleted for') && $inSite(), "edit page: “Delete for site” is refused while that site uses it (HTTP $status: " . substr((string)($json['message'] ?? $body), 0, 200) . ')');
$elements->deleteElement($perSiteSource, true);
$flush();
[$status] = $editorHttp->action('elements/delete-for-site', ['elementId' => $perSite->id, 'siteId' => $allSites[0]->id]);
check($status === 200 && !$inSite(), "and allowed once nothing uses it there (HTTP $status)");
$elements->deleteElement($perSite, true);
$entriesService->deleteSection($perSiteSection);
// with the permission
Craft::$app->getUserPermissions()->saveUserPermissions($editor->id, [...$editorPermissions, ElementRelations::PERMISSION_IN_USE]);
[$status] = $indexAction(craft\elements\actions\SetStatus::class, ['status' => 'disabled'], $inUse);
check($status === 200 && !$isLive($inUse), "with “Delete or disable elements that are in use” the editor may (HTTP $status)");
if (defined(craft\base\Element::class . '::EVENT_DEFINE_DELETION_BLOCKERS')) {
    $inUse->enabled = true;
    $elements->saveElement($inUse);
    [, , $json] = $dialog('deletion-blockers');
    $ours = array_values(array_filter($json['blockers'] ?? [], fn($b) => str_starts_with($b['summary'] ?? '', 'Element Relations found')));
    check($ours && array_filter($ours[0]['actions'], fn($a) => ($a['label'] ?? '') === 'Delete anyway'), 'with the permission the dialog offers “Delete anyway”');
}
$inUse->enabled = true;
$elements->saveElement($inUse);
// --- the replace endpoints refuse what a user may not do, and what makes no sense
echo "Replace endpoints: refusals\n";
Craft::$app->getUserPermissions()->saveUserPermissions($editor->id, $editorPermissions);
$refuseFrom = $article('E2E refuse from');
$refuseTo = $article('E2E refuse to');
$refuseSource = $article('E2E refuse source', ['relatedArticles' => [$refuseFrom->id]]);
$flush();
$replaceParams = ['fromId' => $refuseFrom->id, 'toId' => $refuseTo->id, 'siteId' => $refuseFrom->siteId, 'sourceIds' => [$refuseSource->id]];
[$status] = $editorHttp->action('element-relations/replace/replace', $replaceParams);
check($status === 403, "without the replace permission: the replace endpoint is forbidden (HTTP $status)");
[$status, $body] = $editorHttp->page('/admin/actions/element-relations/replace/wizard?' . http_build_query(['fromId' => $refuseFrom->id, 'siteId' => $refuseFrom->siteId, 'sourceIds' => [$refuseSource->id]]));
check($status === 403 && str_contains($body, 'not permitted to replace'), "…and so is the wizard (HTTP $status)");
[$status] = $editorHttp->action('element-relations/replace/rollback', ['operationId' => 1]);
check($status === 403, "without the rollback permission: the rollback endpoint is forbidden (HTTP $status)");
[$status, , $json] = $http->action('element-relations/replace/replace', ['toId' => []] + $replaceParams);
check($status === 400 && ($json['message'] ?? '') === Craft::t('element-relations', 'replace-choose-replacement'), "without a replacement: “{$json['message']}”");
[$status, $body] = $http->page('/admin/actions/element-relations/replace/wizard?' . http_build_query(['fromId' => 999999999, 'siteId' => $refuseFrom->siteId, 'sourceIds' => [$refuseSource->id]]));
check($status === 403 && str_contains($body, 'Unknown element'), "the wizard for an unknown element is forbidden (HTTP $status)");
// a list narrowed by a filter rule (Section: not this one): nothing of it is in that list, nothing is replaced
$otherSection = Craft::$app->getEntries()->getSectionByHandle('pages');
$notJournal = ['conditionRules' => [['class' => craft\elements\conditions\entries\SectionConditionRule::class, 'operator' => 'in', 'values' => [$otherSection->uid]]]];
[$status, , $json] = $http->action('element-relations/replace/replace', ['scope' => 'list', 'criteria' => json_encode(['filterConfig' => $notJournal])] + $replaceParams);
check($status === 400 && ($json['message'] ?? '') === Craft::t('element-relations', 'replace-nothing-selected') && in_array('E2E refuse source', $relating($refuseFrom), true), "a list filtered to another section has none of these: “{$json['message']}”, nothing changes");
// an admin's replacement: the editor may neither follow it nor roll it back, even with both permissions
[$status, , $json] = $http->action('element-relations/replace/replace', $replaceParams);
$adminsId = (int)($json['operationId'] ?? 0);
$runReplaceJob($adminsId);
Craft::$app->getUserPermissions()->saveUserPermissions($editor->id, [...$editorPermissions, ElementRelations::PERMISSION_REPLACE, ElementRelations::PERMISSION_ROLLBACK]);
[$status] = $editorHttp->action('element-relations/replace/status', ['operationId' => $adminsId]);
check($status === 403, "someone else's replacement: its status is not shown to an editor (HTTP $status)");
$hidden = Entry::find()->section('pages')->one() ?? throw new RuntimeException('no test page');
[$status, $body] = $editorHttp->action('element-relations/replace/replace', ['toId' => [$hidden->id]] + $replaceParams);
check($status === 403 && str_contains($body, 'not permitted to view one of the elements'), "a replacement the editor may not view is forbidden (HTTP $status)");
[$status] = $editorHttp->action('element-relations/replace/rollback', ['operationId' => $adminsId]);
check($status === 403, "…nor can the editor roll it back (HTTP $status)");
[$status, , $json] = $http->action('element-relations/replace/resume', ['operationId' => $adminsId]);
check($status === 400 && ($json['message'] ?? '') === Craft::t('element-relations', 'resume-not-needed'), "resuming one that is done: “{$json['message']}”");
// what the status says about elements that were skipped, for a replacement and its rollback
$withSkipped = internetztube\elementRelations\records\ReplacementRecord::findOne($adminsId);
$withSkipped->result = json_encode(['replaced' => 1, 'skipped' => ['E2E locked entry (no permission)']] + $withSkipped->getResult());
$withSkipped->save(false);
[, , $json] = $http->action('element-relations/replace/status', ['operationId' => $adminsId]);
check(str_contains($json['message'] ?? '', 'E2E locked entry (no permission)'), 'the status names what was skipped: ' . ($json['message'] ?? ''));
$withSkipped->status = internetztube\elementRelations\records\ReplacementRecord::STATUS_ROLLED_BACK;
$withSkipped->result = json_encode(['restored' => 1, 'rollbackSkipped' => ['E2E changed entry (changed since)']] + $withSkipped->getResult());
$withSkipped->save(false);
[, , $json] = $http->action('element-relations/replace/status', ['operationId' => $adminsId]);
check(str_contains($json['message'] ?? '', 'E2E changed entry (changed since)'), 'and for a rollback: ' . ($json['message'] ?? ''));
// a rollback that could do nothing leaves the replacement applied, and says so
$withSkipped->status = internetztube\elementRelations\records\ReplacementRecord::STATUS_APPLIED;
$withSkipped->save(false);
[, , $json] = $http->action('element-relations/replace/status', ['operationId' => $adminsId]);
check(str_starts_with($json['message'] ?? '', Craft::t('element-relations', 'rollback-nothing')) && str_contains($json['message'], 'E2E changed entry (changed since)') && ($json['canUndo'] ?? false) === true, 'a rollback that did nothing: says so, Undo stays: ' . ($json['message'] ?? ''));
// a replacement that found nothing to change
$withSkipped->result = json_encode(['replaced' => 0, 'skipped' => []]);
$withSkipped->save(false);
[, , $json] = $http->action('element-relations/replace/status', ['operationId' => $adminsId]);
check(($json['message'] ?? '') === Craft::t('element-relations', 'replace-nothing-done', ['from' => (string)$refuseFrom]) && ($json['canUndo'] ?? true) === false, 'a replacement that changed nothing says so, without Undo: ' . ($json['message'] ?? ''));
// the history's Resume button on a stuck one
$withSkipped->status = internetztube\elementRelations\records\ReplacementRecord::STATUS_QUEUED;
$withSkipped->save(false);
$withSkipped->updateAttributes(['dateUpdated' => craft\helpers\Db::prepareDateForDb(new DateTime('-20 minutes'))]);
[$status, , $json] = $http->action('element-relations/replace/resume', ['operationId' => $adminsId]);
check($status === 200 && ($json['message'] ?? '') === Craft::t('element-relations', 'resume-done'), "resume on a stuck one queues it again: “{$json['message']}”");
(new craft\db\Query())->createCommand()->delete(craft\db\Table::QUEUE, ['like', 'description', Craft::t('element-relations', 'replace-job-description') . '%', false])->execute();
foreach ([$refuseSource, $refuseTo, $refuseFrom] as $entry) {
    $elements->deleteElement($entry, true);
}

// Lite: nothing is guarded
Craft::$app->getUserPermissions()->saveUserPermissions($editor->id, $editorPermissions);
$switchEdition('lite');
[$status] = $indexAction(craft\elements\actions\SetStatus::class, ['status' => 'disabled'], $inUse);
check($status === 200 && !$isLive($inUse), "Lite: no guard (HTTP $status)");
$switchEdition('pro');
foreach ([...Entry::find()->section('journal')->title('E2E *')->status(null)->all(), $hiddenSource, $editor] as $element) {
    $elements->deleteElement($element, true);
}

// --- Lite: no action, endpoint refused
echo "Lite\n";
$switchEdition('lite');
check(!$offersReplace($usageIndex($from)), 'Lite: the usage list offers no "Replace…"');
[$status] = $http->action('element-relations/replace/replace', ['fromId' => $from->id, 'toId' => $target->id, 'siteId' => $from->siteId, 'sourceIds' => $ticked]);
check($status === 403, "Lite: replace endpoint is forbidden (HTTP $status)");
[$status] = $http->action('element-relations/replace/rollback', ['operationId' => 1]);
check($status === 403, "Lite: rollback endpoint is forbidden (HTTP $status)");
[, $html] = $http->page('/admin/utilities/element-relations');
check(!str_contains($html, 'element-relations-history'), 'Lite: no history in the utility');

// --- cleanup
$switchEdition($editionBefore);
foreach (internetztube\elementRelations\records\ReplacementRecord::find()->where(['>', 'dateCreated', craft\helpers\Db::prepareDateForDb($startedAt)])->all() as $operation) {
    $operation->delete(); // the operations this run created
}
foreach ([...array_values($sources), $target, $from] as $entry) {
    $elements->deleteElement($entry, true);
}
// the protection switch, off and on again through the settings form like an admin would; last, because the web
// request changes project config, which this process then holds an out-of-date copy of
$protect = fn(): mixed => json_decode((string)(new craft\db\Query())->select('value')->from('{{%projectconfig}}')->where(['path' => 'plugins.element-relations.settings.protectInUse'])->scalar());
[$offStatus] = $http->action('plugins/save-plugin-settings', ['pluginHandle' => 'element-relations', 'settings' => ['bulkRefreshBatchSize' => 2000, 'protectInUse' => '']]);
$off = $protect();
[$onStatus] = $http->action('plugins/save-plugin-settings', ['pluginHandle' => 'element-relations', 'settings' => ['bulkRefreshBatchSize' => 2000, 'protectInUse' => '1']]);
check($offStatus === 200 && $off === false && $onStatus === 200 && $protect() === true, "the switch saves off and on (HTTP $offStatus, $onStatus)");
echo "All good.\n";
