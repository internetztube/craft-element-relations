<?php

declare(strict_types=1);

/**
 * Load test for elements with thousands of usages: one asset ("Stress target") used by N entries, then timings of every
 * path that touches all usages. Meant for a separate project (seed + N entries make every other test slow):
 *
 *     PROJECT_DIR=../craft-element-relations-stress PORT=8083 dev/dev.sh up
 *     php dev/stress.php ../craft-element-relations-stress create 10000
 *     php dev/stress.php ../craft-element-relations-stress measure
 */

use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use internetztube\elementRelations\elements\Usage;
use internetztube\elementRelations\fields\ElementRelationsField;
use internetztube\elementRelations\models\RelationsModel;
use internetztube\elementRelations\records\ReplacementRecord;
use internetztube\elementRelations\services\ReplaceService;

require ($argv[1] ?? __DIR__) . '/bootstrap.php';
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

/** Runs $fn as a control panel request of the admin (like dev/smoke.php's helper of the same name). */
function withWebRequest(callable $fn): mixed
{
    $request = Craft::$app->getRequest();
    $response = Craft::$app->getResponse();
    Craft::$app->set('request', ['class' => craft\web\Request::class, 'isCpRequest' => true, 'cookieValidationKey' => Craft::$app->getConfig()->getGeneral()->securityKey, 'url' => '/admin']);
    Craft::$app->set('response', ['class' => craft\web\Response::class]);
    Craft::$app->getUser()->setIdentity(craft\elements\User::find()->username('admin')->one());
    try {
        return $fn();
    } finally {
        Craft::$app->set('request', $request);
        Craft::$app->set('response', $response);
    }
}

$phase = $argv[2] ?? 'measure';
$elements = Craft::$app->getElements();

function stressTarget(string $title): Asset
{
    $existing = Asset::find()->volume('test')->title($title)->one();
    if ($existing) {
        return $existing;
    }
    $volume = Craft::$app->getVolumes()->getVolumeByHandle('test');
    $file = tempnam(sys_get_temp_dir(), 'er') . '.png';
    file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $asset = new Asset(['tempFilePath' => $file, 'filename' => 'stress.png', 'title' => $title, 'newFolderId' => Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)->id, 'volumeId' => $volume->id, 'avoidFilenameConflicts' => true]);
    $asset->setScenario(Asset::SCENARIO_CREATE);
    Craft::$app->getElements()->saveElement($asset) or throw new RuntimeException(json_encode($asset->getErrors()));
    return $asset;
}

/** @return array{float, int} seconds, peak memory (MB) */
function timed(string $label, callable $fn): mixed
{
    gc_collect_cycles();
    memory_reset_peak_usage();
    $start = microtime(true);
    $result = $fn();
    printf("  %-62s %7.2f s  %5d MB peak\n", $label, microtime(true) - $start, memory_get_peak_usage() / 1048576);
    return $result;
}

$target = stressTarget('Stress target');
$replacement = stressTarget('Stress replacement');

if ($phase === 'create') {
    $count = (int)($argv[3] ?? 10000);
    $section = Craft::$app->getEntries()->getSectionByHandle('pages');
    $existing = (int)Entry::find()->section('pages')->title('Stress *')->status(null)->count();
    echo "Creating " . max(0, $count - $existing) . " entries that use “{$target->title}” (#$target->id)…\n";
    for ($i = $existing + 1; $i <= $count; $i++) {
        $entry = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => sprintf('Stress %05d', $i)]);
        $entry->setFieldValue('relatedAssets', [$target->id]);
        $entry->resaving = true; // like a bulk resave: no revision per entry, much faster
        $elements->saveElement($entry, false, true, false) or throw new RuntimeException(json_encode($entry->getErrors()));
        if ($i % 500 === 0) {
            echo "  $i\n";
            gc_collect_cycles();
        }
    }
    // one batched rebuild instead of a refresh job per entry
    (new craft\db\Query())->createCommand()->delete(craft\db\Table::QUEUE)->execute();
    Craft::$app->getQueue()->push(new internetztube\elementRelations\jobs\GenerateResaveAllElementRelationsJobsJob());
    Craft::$app->getQueue()->run();
    echo "Done: " . (new RelationsModel((int)$target->id, (int)$target->siteId))->getCountFast([]) . " usages.\n";
    exit(0);
}

// --- measure
$model = new RelationsModel((int)$target->id, (int)$target->siteId);
$total = $model->getCountFast([]);
echo "“{$target->title}” has $total usages.\n";
$field = Craft::$app->getFields()->getFieldByHandle('relations');
$viewer = User::find()->username('stress-viewer')->status(null)->one();
if (!$viewer) {
    $viewer = new User(['username' => 'stress-viewer', 'email' => 'stress-viewer@example.com']);
    $elements->saveElement($viewer) or throw new RuntimeException(json_encode($viewer->getErrors()));
    $pages = Craft::$app->getEntries()->getSectionByHandle('pages');
    $volume = Craft::$app->getVolumes()->getVolumeByHandle('test');
    Craft::$app->getUserPermissions()->saveUserPermissions($viewer->id, ['accesscp', "viewentries:$pages->uid", "viewpeerentries:$pages->uid", "viewassets:$volume->uid", "viewpeerassets:$volume->uid", ...array_map(fn($s) => "editsite:$s->uid", Craft::$app->getSites()->getAllSites())]);
}
$asViewer = function (callable $fn) use ($viewer) {
    return withWebRequest(function () use ($fn, $viewer) {
        Craft::$app->getUser()->setIdentity($viewer);
        return $fn();
    });
};

timed('count (fast, SQL)', fn() => $model->getCountFast([]));
timed('counts per type (SQL)', fn() => $model->getCountsByType([]));
timed('usage list, first page of 50 (admin)', fn() => count((function () use ($target) {
    $query = Usage::find()->allSites()->status(null)->drafts(null)->limit(50);
    Craft::configure($query, ['usesElement' => $target->id]);
    return $query->all();
})()));
timed('usage list, total for pagination (admin)', fn() => (function () use ($target) {
    $query = Usage::find()->allSites()->status(null)->drafts(null);
    Craft::configure($query, ['usesElement' => $target->id]);
    return $query->count();
})());
timed('field input HTML (admin)', fn() => strlen(withWebRequest(fn() => $field->getInputHtml($target->getFieldValue('relations') ?? $model, $target))));
timed('index column preview (admin)', fn() => strlen(withWebRequest(fn() => $field->getPreviewHtml($model, $target))));
internetztube\elementRelations\elements\db\UsageQuery::resetPermissionCache();
timed('field input HTML (restricted user, cold)', fn() => strlen($asViewer(fn() => $field->getInputHtml($model, $target))));
timed('index column preview (restricted user, warm)', fn() => strlen($asViewer(fn() => $field->getPreviewHtml($model, $target))));
internetztube\elementRelations\elements\db\UsageQuery::resetPermissionCache();
timed('index column preview (restricted user, cold)', fn() => strlen($asViewer(fn() => $field->getPreviewHtml($model, $target))));
timed('revision hint (wizard)', fn() => ElementRelationsField::revisionHintFor($target));

if (in_array('replace', $argv, true)) {
    Craft::$app->getUser()->setIdentity(User::find()->username('admin')->one());
    $result = timed("replace everywhere, queued job ($total usages)", function () use ($target, $replacement) {
        $result = ReplaceService::replace($target, $replacement, queue: true);
        Craft::$app->getQueue()->run();
        return $result;
    });
    $operation = ReplacementRecord::findOne($result['operationId']);
    printf("  -> %s, %d replaced, changes %d KB\n", $operation->status, $operation->getResult()['replaced'] ?? 0, strlen((string)$operation->changes) / 1024);
    timed('roll back, queued job', function () use ($operation) {
        ReplaceService::queueRollback($operation);
        Craft::$app->getQueue()->run();
    });
    $operation = ReplacementRecord::findOne($operation->id);
    printf("  -> %s, %d restored; “%s” has %d usages again\n", $operation->status, $operation->getResult()['restored'] ?? 0, $target->title, (new RelationsModel((int)$target->id, (int)$target->siteId))->getCountFast([]));
}
