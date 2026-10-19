<?php
/**
 * Seeds a Craft project for plugin development and the smoke test. Idempotent.
 *
 * Base content: section "Pages" with an Entries field + the Element Relations field,
 * "Page A1..A12" (A12 disabled) relating to "Page B", "Page C" as the target for third-party
 * field tests, an asset and a test user.
 *
 * Every `dev/seed/<name>.php` returns a closure `function (array $ctx): void` that creates the
 * field(s) of one integration and one source entry relating to `$ctx['target']` (Page C) or
 * `$ctx['asset']`. Its counterpart `dev/smoke/<name>.php` asserts the relation.
 *
 * Run: dev/dev.sh seed   (or: php dev/seed.php <craft-project-dir>)
 */
require ($argv[1] ?? __DIR__) . '/bootstrap.php';
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

/** `ONLY=matrix,neo` limits the integration seeds/smoke tests to those names (default: all). */
function onlySelected(string $file): bool
{
    $only = array_filter(explode(',', (string)getenv('ONLY')));
    return !$only || in_array(basename($file, '.php'), $only, true);
}

use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Entries as EntriesField;
use craft\fs\Local;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Volume;
use internetztube\elementRelations\fields\ElementRelationsField;

function fail(object $model): never
{
    throw new RuntimeException(get_class($model) . ': ' . print_r($model->getErrors(), true));
}

/** Appends a field to the end of the first tab of the entry type's field layout (no-op if already there). */
function addFieldToLayout(FieldInterface $field, EntryType $entryType): void
{
    $layout = $entryType->getFieldLayout();
    if ($layout->getFieldByHandle($field->handle)) {
        return;
    }
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($field)]);
    $layout->setTabs($layout->getTabs());
    $entryType->setFieldLayout($layout);
    Craft::$app->getEntries()->saveEntryType($entryType) or fail($entryType);
}

/** Creates (or returns) a field; `$config` needs at least `name` and `handle`. */
function ensureField(string $class, array $config): FieldInterface
{
    if ($field = Craft::$app->getFields()->getFieldByHandle($config['handle'])) {
        return $field;
    }
    $field = new $class($config);
    Craft::$app->getFields()->saveField($field) or fail($field);
    return $field;
}

/**
 * Creates an entry of the Pages section, or updates an existing one, with the given field values.
 * Values are always (re)applied: a field created earlier in the same PHP process is not always writable yet
 * (Craft caches field layouts/behaviours per request), so dev.sh runs this seed twice and the second, fresh
 * process writes the content for sure.
 */
function ensureEntry(string $title, array $fieldValues = [], bool $enabled = true): Entry
{
    if ($entry = Entry::find()->section('pages')->title($title)->status(null)->one()) {
        if ($fieldValues) {
            $entry->setFieldValues($fieldValues);
            Craft::$app->getElements()->saveElement($entry) or fail($entry);
        }
        return $entry;
    }
    $section = Craft::$app->getEntries()->getSectionByHandle('pages');
    $entry = new Entry([
        'sectionId' => $section->id,
        'typeId' => $section->getEntryTypes()[0]->id,
        'title' => $title,
        'enabled' => $enabled,
    ]);
    $entry->setFieldValues($fieldValues);
    Craft::$app->getElements()->saveElement($entry) or fail($entry);
    return $entry;
}

$entries = Craft::$app->getEntries();
$siteId = Craft::$app->getSites()->getPrimarySite()->id;

// 1. Section + entry type (project config may already contain them after a `dev.sh reset`).
$section = $entries->getSectionByHandle('pages');
if (!$section) {
    $related = ensureField(EntriesField::class, ['name' => 'Related Entries', 'handle' => 'relatedEntries']);
    $relations = ensureField(ElementRelationsField::class, ['name' => 'Relations', 'handle' => 'relations']);

    $layout = new FieldLayout(['type' => Entry::class]);
    $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
    $tab->setElements([new EntryTitleField(), new CustomField($related), new CustomField($relations)]);
    $layout->setTabs([$tab]);

    $entryType = new EntryType(['name' => 'Page', 'handle' => 'page']);
    $entryType->setFieldLayout($layout);
    $entries->saveEntryType($entryType) or fail($entryType);

    $section = new Section([
        'name' => 'Pages',
        'handle' => 'pages',
        'type' => Section::TYPE_CHANNEL,
        'siteSettings' => [new Section_SiteSettings([
            'siteId' => $siteId,
            'hasUrls' => true,
            'uriFormat' => 'pages/{slug}',
            'template' => 'index',
        ])],
    ]);
    $section->setEntryTypes([$entryType]);
    $entries->saveSection($section) or fail($section);
}
$entryType = $section->getEntryTypes()[0];

// 2. Asset volume with one image (for SEOmatic, user photos, asset links).
$volumes = Craft::$app->getVolumes();
if (!Craft::$app->getFs()->getFilesystemByHandle('testFs')) {
    $fs = Craft::$app->getFs()->createFilesystem([
        'type' => Local::class,
        'name' => 'Test FS',
        'handle' => 'testFs',
        'settings' => ['path' => '@storage/test-assets', 'url' => '@web/test-assets'],
    ]);
    Craft::$app->getFs()->saveFilesystem($fs) or fail($fs);
}
if (!$volume = $volumes->getVolumeByHandle('test')) {
    $volume = new Volume(['name' => 'Test', 'handle' => 'test', 'fsHandle' => 'testFs']);
    $volumes->saveVolume($volume) or fail($volume);
}
if (!$asset = Asset::find()->volume('test')->orderBy(['elements.id' => SORT_ASC])->one()) {
    $tmp = tempnam(sys_get_temp_dir(), 'er') . '.png';
    // 1x1 transparent PNG
    file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $asset = new Asset([
        'tempFilePath' => $tmp,
        'filename' => 'test.png',
        'newFolderId' => Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)->id,
        'volumeId' => $volume->id,
        'avoidFilenameConflicts' => true,
    ]);
    $asset->setScenario(Asset::SCENARIO_CREATE);
    Craft::$app->getElements()->saveElement($asset) or fail($asset);
}

// The relations field on assets too (assets are the typical "where is this used?" case).
$relationsField = Craft::$app->getFields()->getFieldByHandle('relations');
$volumeLayout = $volume->getFieldLayout();
if ($relationsField && !$volumeLayout->getFieldByHandle('relations')) {
    $tab = $volumeLayout->getTabs()[0] ?? new FieldLayoutTab(['name' => 'Content', 'layout' => $volumeLayout]);
    $tab->setElements([...$tab->getElements(), new CustomField($relationsField)]);
    $volumeLayout->setTabs([$tab]);
    $volume->setFieldLayout($volumeLayout);
    $volumes->saveVolume($volume) or fail($volume);
}

// 3. Base content.
$b = ensureEntry('Page B');
$c = ensureEntry('Page C');
for ($i = 1; $i <= 12; $i++) {
    ensureEntry("Page A$i", ['relatedEntries' => [$b->id]], $i < 12);
}

// 4. Integration seeds.
$ctx = [
    'siteId' => $siteId,
    'section' => $section,
    'entryType' => $entryType,
    'target' => $c,
    'asset' => $asset,
];
foreach (glob(__DIR__ . '/seed/*.php') as $file) {
    if (!onlySelected($file)) {
        continue;
    }
    echo "Seeding " . basename($file, '.php') . "\n";
    (require $file)($ctx);
}

// A bootstrapped script never fires EVENT_AFTER_REQUEST, which is where Craft normally persists project config
// changes (filesystems live only there), so write them out explicitly.
Craft::$app->getProjectConfig()->saveModifiedConfigData();

echo "Seeded. Targets: Page B (#{$b->id}) for base tests, Page C (#{$c->id}) and asset #{$asset->id} for integrations.\n";
