<?php

declare(strict_types=1);

/**
 * Seeds the Craft 6 dev project for plugin development and the smoke test. Idempotent.
 *
 * Base content: section "Pages" with an Entries field + the Element Relations field, "Page A1..A12" (A12 disabled)
 * relating to "Page B", "Page C" as the target for the integration tests, a second site and an asset.
 *
 * Every `dev/seed/<name>.php` returns a closure `function (array $ctx): void` that creates the field(s) of one
 * integration and one source entry relating to `$ctx['target']` (Page C) or `$ctx['asset']`. Its counterpart
 * `dev/smoke/<name>.php` asserts the relation.
 *
 * Run: dev/dev.sh seed   (or: php dev/seed.php <craft-project-dir>)
 */

require __DIR__ . '/craft.php';
require __DIR__ . '/lib.php';

use CraftCms\Cms\Asset\Data\Volume;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Asset\Folders;
use CraftCms\Cms\Asset\Validation\AssetRules;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Entries as EntriesField;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\Volumes;
use Internetztube\ElementRelations\Fields\ElementRelationsField;

// 0a. Plugin config with the feature flags on, so the dev project and CI exercise the two-edition path too.
$configFile = config_path('craft/element-relations.php');
if (!file_exists($configFile)) {
    file_put_contents($configFile, "<?php\n\nreturn [\n    'features' => ['editions' => true],\n];\n");
}

// 0b. Second site, created before anything else (sections and groups created later get both sites).
if (!Sites::getSiteByHandle('de')) {
    $de = new Site([
        'groupId' => Sites::getPrimarySite()->groupId,
        'name' => 'German',
        'handle' => 'de',
        'language' => 'de',
        'hasUrls' => true,
        'baseUrl' => rtrim((string)config('app.url'), '/') . '/de',
    ]);
    Sites::saveSite($de) or fail($de);
}
$siteId = (int)Sites::getPrimarySite()->id;

// 1. Fields, entry type and section.
$related = ensureField(EntriesField::class, 'relatedEntries', 'Related Entries');
$relations = ensureField(ElementRelationsField::class, 'relations', 'Relations');
$entryType = ensureEntryType('page', 'Page', [$related, $relations]);
$section = ensureSection('pages', 'Pages', $entryType, [$siteId]);

// 2. Asset volume with one image, on Laravel's public disk (served from /storage, see `artisan storage:link`).
if (!$volume = Volumes::getVolumeByHandle('test')) {
    $volume = new Volume(['name' => 'Test', 'handle' => 'test', 'fsHandle' => 'public', 'subpath' => 'test-assets', 'hasUrls' => true]);
    $volume->setFieldLayout(layoutFor(Asset::class, [$relations]));
    Volumes::saveVolume($volume) or fail($volume);
}
if (!$asset = Asset::find()->volume('test')->orderBy('elements.id')->one()) {
    $tmp = tempnam(sys_get_temp_dir(), 'er') . '.png';
    file_put_contents($tmp, base64_decode(PNG_1X1));
    $asset = Elements::createElement([
        'type' => Asset::class,
        'volumeId' => $volume->id,
        'newFolderId' => app(Folders::class)->getRootFolderByVolumeId((int)$volume->id)->id,
        'tempFilePath' => $tmp,
        'newFilename' => 'test.png',
        'avoidFilenameConflicts' => true,
    ]);
    $asset->ruleset->useScenario(AssetRules::SCENARIO_CREATE);
    Elements::saveElement($asset) or fail($asset);
}

// 3. Base content.
$b = ensureEntry('Page B');
$c = ensureEntry('Page C');
for ($i = 1; $i <= 12; $i++) {
    ensureEntry("Page A$i", ['relatedEntries' => [$b->id]], $i < 12);
}

// 4. Integration seeds.
$ctx = ['siteId' => $siteId, 'section' => $section, 'entryType' => $entryType, 'target' => $c, 'asset' => $asset];
foreach (glob(__DIR__ . '/seed/*.php') ?: [] as $file) {
    if (onlySelected($file)) {
        echo 'Seeding ' . basename($file, '.php') . "\n";
        (require $file)($ctx);
    }
}

// the tests switch editions and restore the one they found; a run that failed midway may have left another one
CraftCms\Cms\Support\Facades\Plugins::switchEdition('element-relations', 'pro');
// a script ends without Laravel's terminate, which would write the project config changes (the edition) to the database
CraftCms\Cms\Support\Facades\ProjectConfig::saveModifiedConfigData();

echo "Seeded. Targets: Page B (#{$b->id}) for base tests, Page C (#{$c->id}) and asset #{$asset->id} for integrations.\n";
