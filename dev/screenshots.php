<?php

declare(strict_types=1);

/**
 * Prints the control panel paths and ids of the demo elements (dev/demo.php) that dev/screenshots.mts shows, as JSON.
 * Run: php dev/screenshots.php <craft-project-dir>
 *      php dev/screenshots.php <craft-project-dir> protect 0|1   the plugin's "Protect elements in use" setting, for
 *      the shot of Craft's delete dialog without the plugin's part
 */

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Plugins;
use CraftCms\Cms\Support\Facades\ProjectConfig;
use CraftCms\Cms\Support\Facades\UserGroups;

require __DIR__ . '/craft.php';

/** The edit page path ("/admin/…") of an element. */
function editPath(ElementInterface $element): string
{
    $url = $element->getCpEditUrl() ?? throw new RuntimeException("$element has no edit page");
    $path = (string)parse_url($url, PHP_URL_PATH);
    $query = parse_url($url, PHP_URL_QUERY);
    return $query ? "$path?$query" : $path;
}

function demoAsset(string $filename): Asset
{
    return Asset::find()->filename($filename)->one() ?? throw new RuntimeException("No demo asset $filename (run dev/dev.sh seed)");
}

function demoEntry(string $section, string $title): Entry
{
    return Entry::find()->section($section)->title($title)->status(null)->one() ?? throw new RuntimeException("No demo entry $title");
}

if (($argv[2] ?? '') === 'protect') {
    $plugin = Plugins::getPlugin('element-relations') ?? throw new RuntimeException('Plugin not installed');
    Plugins::savePluginSettings($plugin, ['protectInUse' => ($argv[3] ?? '1') === '1']) or throw new RuntimeException('Settings not saved');
    ProjectConfig::saveModifiedConfigData(); // for the web server (a script never terminates the app)
    exit(0);
}

$skyline = demoAsset('train-and-city-towers.jpg');
$guide = demoAsset('rail-pass-guide-2026.pdf');
$editors = UserGroups::getGroupByHandle('editors') ?? throw new RuntimeException('No Editors group');
$usedIn = Fields::getFieldByHandle('usedIn') ?? throw new RuntimeException('No Used in field');

echo json_encode([
    'skyline' => ['id' => $skyline->id, 'path' => editPath($skyline)],
    'replacement' => ['id' => demoAsset('waiting-on-the-platform.jpg')->id],
    'replaceIn' => [demoEntry('homepage', 'Homepage')->id, demoEntry('journal', 'How to read a station')->id],
    // used by a link field and an article's body
    'guide' => ['id' => $guide->id, 'path' => editPath($guide)],
    // a user's photo and a team entry's portrait: two element types in its list
    'portrait' => ['path' => editPath(demoAsset('mara-lindqvist.jpg'))],
    'hongKong' => ['id' => demoEntry('destinations', 'Hong Kong')->id, 'path' => editPath(demoEntry('destinations', 'Hong Kong'))],
    'homepage' => ['path' => editPath(demoEntry('homepage', 'Homepage'))],
    'usedInField' => ['path' => "/admin/settings/fields/edit/$usedIn->id"],
    'editors' => ['path' => "/admin/settings/users/groups/$editors->id"],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
