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
    private string $jar;
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
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
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
$switchEdition('pro');

// --- fixtures (through Craft, as the console smoke does)
echo "Fixtures\n";
$section = Craft::$app->getEntries()->getSectionByHandle('pages') ?? throw new RuntimeException('Run the seed first');
$typeId = $section->getEntryTypes()[0]->id;
foreach (Entry::find()->title('E2E *')->status(null)->drafts(null)->provisionalDrafts(null)->all() as $old) {
    $elements->deleteElement($old, true);
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
$listUsages = function (Entry $of) use ($http): array {
    [$status, $body, $json] = $http->action('element-indexes/get-elements', [
        'elementType' => Usage::class,
        'context' => 'modal',
        'source' => '*',
        'criteria' => ['usesElement' => $of->id, 'allSites' => 1, 'siteId' => $of->siteId],
        'viewState' => ['mode' => 'table'],
    ]);
    check($status === 200 && isset($json['html']), "get-elements answers (HTTP $status: " . substr($body, 0, 300) . ')');
    if (getenv('E2E_DEBUG')) {
        file_put_contents(getenv('E2E_DEBUG'), $json['html']);
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

// --- Lite: no button, endpoint refused
echo "Lite\n";
$switchEdition('lite');
[, $html] = $http->page('/admin/content/entries/pages/' . $from->id);
check(!str_contains($html, 'data-element-relations-replace'), 'Lite: no wizard button on the edit page');
[$status] = $http->action('element-relations/replace/replace', ['fromId' => $from->id, 'toId' => $target->id, 'siteId' => $from->siteId, 'sourceIds' => $ticked]);
check($status === 403, "Lite: replace endpoint is forbidden (HTTP $status)");

// --- cleanup
$switchEdition($editionBefore);
foreach ([...array_values($sources), $target, $from] as $entry) {
    $elements->deleteElement($entry, true);
}
echo "All good.\n";
