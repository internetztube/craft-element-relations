<?php

declare(strict_types=1);

/**
 * Prints the control panel paths and ids of the demo elements (dev/demo.php) that dev/screenshots.mts shows, as JSON.
 * Run: php dev/screenshots.php <craft-project-dir>
 */

use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;

require ($argv[1] ?? __DIR__) . '/bootstrap.php';
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

/** The edit page path ("/admin/…") of an element. */
function editPath(ElementInterface $element): string
{
    $url = $element->getCpEditUrl() ?? throw new RuntimeException("$element has no edit page");
    $path = (string)parse_url($url, PHP_URL_PATH);
    $query = parse_url($url, PHP_URL_QUERY);
    return $query ? "$path?$query" : $path;
}

function asset(string $filename): Asset
{
    return Asset::find()->filename($filename)->one() ?? throw new RuntimeException("No demo asset $filename (run dev/dev.sh seed)");
}

function entry(string $section, string $title): Entry
{
    return Entry::find()->section($section)->title($title)->status(null)->one() ?? throw new RuntimeException("No demo entry $title");
}

$skyline = asset('train-and-city-towers.jpg');
$editors = Craft::$app->getUserGroups()->getGroupByHandle('editors') ?? throw new RuntimeException('No Editors group');
$usedIn = Craft::$app->getFields()->getFieldByHandle('usedIn') ?? throw new RuntimeException('No Used in field');

echo json_encode([
    'skyline' => ['id' => $skyline->id, 'path' => editPath($skyline)],
    'replacement' => ['id' => asset('waiting-on-the-platform.jpg')->id],
    'replaceIn' => [entry('homepage', 'Homepage')->id, entry('journal', 'How to read a station')->id],
    'social' => ['path' => editPath(asset('social-share.svg'))],
    'guide' => ['path' => editPath(asset('rail-pass-guide-2026.pdf'))],
    'hongKong' => ['id' => entry('destinations', 'Hong Kong')->id, 'path' => editPath(entry('destinations', 'Hong Kong'))],
    'taipei' => ['id' => entry('destinations', 'Taipei')->id, 'path' => editPath(entry('destinations', 'Taipei'))],
    'notes' => ['id' => asset('station-notes.jpg')->id, 'path' => editPath(asset('station-notes.jpg'))],
    'homepage' => ['path' => editPath(entry('homepage', 'Homepage'))],
    // the form redirect target: only Formie knows it is used, Craft's own delete dialog does not
    'thankYou' => ['id' => entry('company', 'Thank you')->id, 'path' => editPath(entry('company', 'Thank you'))],
    'usedInField' => ['path' => "/admin/settings/fields/edit/$usedIn->id"],
    'editors' => ['path' => "/admin/settings/users/groups/$editors->id"],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
