<?php
/**
 * GUI-level test of the control panel paths, against a running dev server (dev/dev.sh serve, dev/dev.sh e2e):
 * logs in over HTTP, renders the edit page, asks Craft's element index endpoint for the usages exactly like the
 * replace wizard's modal does, submits those ids to the replace endpoint and verifies the result through Craft.
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

// --- the edit page renders the field with Craft's index and the Pro wizard button
echo "Edit page\n";
[$status, $html] = $http->page('/admin/content/entries/pages/' . $from->id);
check($status === 200, "edit page renders (HTTP $status)");
check(str_contains($html, 'Craft.createElementIndex') && str_contains($html, '"usesElement":' . $from->id) && str_contains($html, '"allSites":true'), 'field embeds the Usage index filtered by this element across all sites');
check(str_contains($html, 'data-element-relations-replace') && str_contains($html, 'element-relations-type-menu') === (count($sources) > 0 && str_contains($html, 'element-relations-type-menu')), 'Pro: the Replace… wizard button is rendered');

// --- what the wizard's second modal lists: Craft's element index endpoint over the Usage type
echo "Usage index endpoint (the wizard's usages modal)\n";
$listUsages = function (craft\base\ElementInterface $of) use ($http): array {
    [$status, $body, $json] = $http->action('element-indexes/get-elements', [
        'elementType' => Usage::class,
        'context' => 'modal',
        'source' => '*',
        'criteria' => ['usesElement' => $of->id, 'allSites' => 1, 'siteId' => $of->siteId],
        'viewState' => ['mode' => 'table'],
    ]);
    check($status === 200 && isset($json['html']), "get-elements answers (HTTP $status: " . substr($body, 0, 300) . ')');
    if (getenv('E2E_DEBUG')) {
        file_put_contents(getenv('E2E_DEBUG') . '-' . $of->id . '-' . ($GLOBALS['e2eDebugN'] = ($GLOBALS['e2eDebugN'] ?? 0) + 1) . '.html', $json['html']);
    }
    // table rows carry the element id the modal submits: <tr data-id="…" data-title="…">
    preg_match_all('/<tr data-id="(\d+)" data-title="([^"]*)"/', $json['html'], $m, PREG_SET_ORDER);
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

// --- the wizard submits those ids to the replace endpoint
echo "Replace endpoint\n";
$ticked = [$sources['field']->id];
if (isset($sources['nested'])) {
    $ticked[] = $sources['nested']->id;
}
[$status, $body, $json] = $http->action('element-relations/replace/replace', ['fromId' => $from->id, 'toId' => $target->id, 'siteId' => $from->siteId, 'sourceIds' => $ticked]);
check($status === 200 && isset($json['message']), "replace succeeds for the ticked usages (HTTP $status: " . substr($body, 0, 200) . ')');
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

// --- the index action ("Replace everywhere…") through Craft's perform-action endpoint
echo "Index action endpoint\n";
[$status, , $json] = $http->action('element-indexes/perform-action', [
    'elementType' => Entry::class,
    'context' => 'index',
    'source' => '*',
    'elementAction' => internetztube\elementRelations\elements\actions\ReplaceRelations::class,
    'elementIds' => [$target->id],
    'replacementId' => $from->id,
    'criteria' => ['siteId' => $from->siteId],
    'viewState' => ['mode' => 'table', 'static' => false, 'inlineEditing' => false, 'tableColumns' => []],
]);
check($status === 200 && isset($json['message']) && str_contains($json['message'], 'replaced by'), "perform-action runs the Replace action (HTTP $status: " . ($json['message'] ?? substr(json_encode($json), 0, 200)) . ')');
$flush();
check(count(array_intersect(array_map('strval', $sources), $relating($from))) === count($sources), 'everything points back to "E2E from" after replacing everywhere');

// --- every seeded usage through the wizard's path, one row at a time
echo "Every usage through the wizard (Page C and the test asset)\n";
$cacheFingerprint = fn() => md5(json_encode((new craft\db\Query())
    ->select(['sourceElementId', 'sourceSiteId', 'sourcePrimaryOwnerId', 'targetElementId', 'targetSiteId', 'fieldId', 'type', 'globalKey'])
    ->from('{{%elementrelations_cache}}')
    ->orderBy(['sourceElementId' => SORT_ASC, 'sourceSiteId' => SORT_ASC, 'targetElementId' => SORT_ASC, 'fieldId' => SORT_ASC, 'type' => SORT_ASC, 'globalKey' => SORT_ASC])
    ->all()));
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
        // forward, exactly as the wizard posts it
        [$status, $body, $json] = $http->action('element-relations/replace/replace', ['fromId' => $origin->id, 'toId' => $replacement->id, 'siteId' => $origin->siteId, 'sourceIds' => [$ownerId]]);
        check($status === 200 && ($json['replaced'] ?? 0) >= 1 && empty($json['skipped']), "$what: replaced, nothing skipped (HTTP $status: " . substr($body, 0, 220) . ')');
        $fromRows = $listUsages($origin);
        $toRows = $listUsages($replacement);
        check(!isset($fromRows[$ownerId]), "$what: gone from the list immediately (no reload, no queue; still listed: " . json_encode((new craft\db\Query())->select(['sourceElementId', 'sourceSiteId', 'type', 'fieldId'])->from('{{%elementrelations_cache}}')->where(['targetElementId' => $origin->id, 'sourcePrimaryOwnerId' => $ownerId])->all()) . ')');
        check(isset($toRows[$ownerId]), "$what: listed under the replacement immediately");
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
        [$status, $body, $json] = $http->action('element-relations/replace/rollback', ['operationId' => $operationId]);
        check($status === 200 && ($json['restored'] ?? 0) >= 1 && empty($json['skipped']), "$what: Undo rolls it back (HTTP $status: " . substr($body, 0, 220) . ')');
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
[$status, , $json] = $http->action('element-relations/replace/replace', ['fromId' => $historyFrom->id, 'toId' => $historyTo->id, 'siteId' => $historyFrom->siteId, 'sourceIds' => [$historySource->id]]);
check($status === 200 && !empty($json['operationId']), 'a replacement for the history');
$operationId = $json['operationId'];
[$status, $html] = $http->page('/admin/utilities/element-relations');
check($status === 200 && str_contains($html, 'id="element-relations-history"'), 'the utility renders the history table');
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
$flush();
check(in_array('E2E history source', $relating($historyFrom), true) && !in_array('E2E history source', $relating($historyTo), true), 'the form rolled the replacement back');
[, $html] = $http->page('/admin/utilities/element-relations');
preg_match('#<tr data-operation-id="' . $operationId . '">(.*?)</tr>#s', $html, $row);
check(!str_contains($row[1] ?? '', 'element-relations/replace/rollback') && str_contains($row[1] ?? '', 'Rolled back'), 'afterwards the row says Rolled back and offers no button');
// revert to revisions through the endpoint (the history's second button posts mode=revisions after a confirm);
// no pauses on purpose: everything may happen within one second, revisions must still be right
[$status, , $json] = $http->action('element-relations/replace/replace', ['fromId' => $historyFrom->id, 'toId' => $historyTo->id, 'siteId' => $historyFrom->siteId, 'sourceIds' => [$historySource->id]]);
check($status === 200 && !empty($json['operationId']), 'a second replacement for the revert');
$revertId = $json['operationId'];
[, $html] = $http->page('/admin/utilities/element-relations');
preg_match('#<tr data-operation-id="' . $revertId . '">(.*?)</tr>#s', $html, $row);
check(str_contains($row[1] ?? '', 'name="mode" value="revisions"') && str_contains($row[1] ?? '', 'confirm('), 'the history offers "Revert to revisions" (with a confirmation) for a versioned entry');
$later = Entry::find()->id($historySource->id)->one();
$later->title = 'E2E history source (edited later)';
$elements->saveElement($later) or throw new RuntimeException(json_encode($later->getErrors()));
$flush();
[$status, $body, $json] = $http->action('element-relations/replace/rollback', ['operationId' => $revertId, 'mode' => 'revisions']);
check($status === 200 && ($json['reverted'] ?? 0) === 1, "revert to revisions answers with one reverted entry (HTTP $status: " . substr($body, 0, 200) . ')');
$flush();
$back = Entry::find()->id($historySource->id)->one();
check((string)$back === 'E2E history source' && in_array('E2E history source', $relating($historyFrom), true), 'the entry is back at its revision: original relation, later title edit discarded');
foreach ([$historySource, $historyTo, $historyFrom] as $entry) {
    $elements->deleteElement($entry, true);
}

// --- Lite: no button, endpoint refused
echo "Lite\n";
$switchEdition('lite');
[, $html] = $http->page('/admin/content/entries/pages/' . $from->id);
check(!str_contains($html, 'data-element-relations-replace'), 'Lite: no wizard button on the edit page');
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
echo "All good.\n";
