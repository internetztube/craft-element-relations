<?php

declare(strict_types=1);

/**
 * The demo site (dev/demo.php): its content is there and reads the way screenshots show it, plus the Field column
 * regression found with it (two usage lists in one request mixed their fields).
 */

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Elements;
use Illuminate\Http\Request;
use Internetztube\ElementRelations\Elements\Usage;
use Internetztube\ElementRelations\Models\RelationsModel;
use Internetztube\ElementRelations\Services\ReplaceService;

return function (array $ctx): void {
    $asset = fn(string $filename, bool $trashed = false): Asset => Asset::find()->filename($filename)->trashed($trashed)->one() ?? throw new RuntimeException("demo asset $filename");
    $entry = fn(string $section, string $title): Entry => Entry::find()->section($section)->title($title)->status(null)->one() ?? throw new RuntimeException("demo entry $title");
    // one row per element (in the site it is shown in): "title:site[:draft]" => its Field column, fields sorted
    // (their order differs between databases); $siteRows: the same keys => the Site column
    $siteRows = [];
    $usages = function (ElementInterface $of) use (&$siteRows): array {
        $rows = [];
        foreach (Usage::find()->allSites()->status(null)->drafts(null)->usesElement($of->id)->all() as $e) {
            $fields = explode(', ', strip_tags((string)$e->getAttributeHtml(Usage::ATTR_FIELD)));
            sort($fields);
            $key = "$e:{$e->getSite()->handle}" . ($e->getIsDraft() ? ':draft' : '');
            $rows[$key] = implode(', ', $fields);
            $siteRows[$key] = strip_tags((string)$e->getAttributeHtml(Usage::ATTR_SITE));
        }
        return $rows;
    };
    $skyline = $asset('train-and-city-towers.jpg');
    $guide = $asset('rail-pass-guide-2026.pdf');
    $skylineRows = $usages($skyline);
    check(($skylineRows['Homepage:default'] ?? null) === 'Hero image' && !isset($skylineRows['Startseite:de']) && ($siteRows['Homepage:default'] ?? null) === 'English, German', 'demo: the skyline photo is the homepage hero, listed once with both sites (' . json_encode($skylineRows) . ')');
    check(($skylineRows['How to read a station:default'] ?? null) === 'Body', 'demo: …embedded in an article body (Markdown)');
    $guideRows = $usages($guide);
    check(($guideRows['Homepage:default'] ?? null) === 'Link' && isset($guideRows["Steel, glass and a bird's nest:default"]), 'demo: the rail pass guide is used by the homepage link and an article (' . json_encode($guideRows) . ')');

    $types = array_unique(array_map(fn(Entry $e) => $e->getType()->handle, Entry::find()->section('journal')->status(null)->all()));
    check(!array_diff(['article', 'photoEssay', 'route', 'review'], $types), 'demo: the journal has every entry type (' . implode(', ', $types) . ')');
    // (the user and the team entry share the title, so not by rows)
    $maraTypes = array_map(fn(ElementInterface $e): string => $e::class, Usage::find()->allSites()->status(null)->usesElement($asset('mara-lindqvist.jpg')->id)->all());
    sort($maraTypes);
    check($maraTypes === [Entry::class, CraftCms\Cms\User\Elements\User::class], 'demo: a portrait is used by a user (photo) and a team entry: two element types in its list (' . implode(', ', $maraTypes) . ')');
    check(($usages($entry('destinations', 'Hong Kong'))['Beijing to Hong Kong in one day:default'] ?? null) === 'Destinations', 'demo: a route stop (Matrix) uses a destination, listed as its route');

    // two lists in a row: the second one shows only its own fields
    $usages($entry('journal', 'Twelve hours on the rails'));
    $jonasRows = $usages($entry('team', 'Jonas Berger'));
    check(($jonasRows['Packing light for a week of trains:default'] ?? null) === 'Written by', 'Field column: only the field linking this element, not the fields of an earlier list in the request (' . ($jonasRows['Packing light for a week of trains:default'] ?? '-') . ')');
    check(isset($jonasRows['Why we take the train:default:draft']), 'demo: the draft of an article shows up as a usage');

    // a never-published article (only a draft)
    $river = $asset('afternoon-by-the-river.jpg');
    check(isset($usages($river)['A ferry to Busan:default:draft']), 'demo: an unpublished draft shows up as a usage (a draft row)');

    // front-end defaults: what visitors may see unless asked for; an explicit null means everything (as in 3.1)
    $asVisitor = function (callable $fn): mixed {
        $before = app('request');
        app()->instance('request', Request::create(rtrim((string)config('app.url'), '/') . '/', 'GET'));
        $console = new ReflectionProperty(app(), 'isRunningInConsole');
        $wasConsole = $console->getValue(app());
        $console->setValue(app(), false);
        try {
            return $fn();
        } finally {
            $console->setValue(app(), $wasConsole);
            app()->instance('request', $before);
        }
    };
    $riverModel = new RelationsModel((int)$river->id, (int)$river->siteId);
    [$visitor, $everything, $draftsOnly, $elementsAll] = $asVisitor(fn() => [
        $riverModel->getCount(),
        $riverModel->getCount(status: null, drafts: null),
        $riverModel->getCount(drafts: true),
        count($riverModel->getElements(status: null, drafts: null)),
    ]);
    check($draftsOnly >= 1 && $everything === $visitor + $draftsOnly && $elementsAll === $everything, "front end: count() is what visitors see ($visitor), count(status: null, drafts: null) everything ($everything), drafts included ($draftsOnly)");
    check($riverModel->getCount() === $everything, 'console: the default is everything (' . $riverModel->getCount() . ')');

    // the trash: a trashed article can be restored, so its usages stay; it is listed under "Trashed" only, counts nowhere
    // and no replacement touches it; restoring brings it back as it was
    $glassRoof = $asset('glass-roof.jpg');
    $outdated = Entry::find()->title('Station guide 2019')->trashed(true)->status(null)->one();
    check($outdated !== null && Asset::find()->filename('timetable-2019.pdf')->trashed(true)->exists(), 'demo: an article and a document are in the trash');
    assert($outdated instanceof Entry);
    $trashedUsages = fn(): array => array_map('strval', Usage::find()->allSites()->status(null)->trashed(true)->usesElement($glassRoof->id)->all());
    $model = fn(): RelationsModel => new RelationsModel((int)$glassRoof->id, (int)$glassRoof->siteId);
    $countBefore = $model()->getCountFast([]);
    check(!isset($usages($glassRoof)['Station guide 2019:default']) && in_array('Station guide 2019', $trashedUsages(), true), 'trashed usage: not in the list, but under "Trashed"');
    check(!in_array((int)$outdated->id, $model()->getSourceElementIds(), true), "trashed usage: not counted ($countBefore)");
    $plan = ReplaceService::replace($glassRoof, $asset('into-the-tunnel.jpg'), [(int)$outdated->id]);
    check($plan['replaced'] === 0, 'trashed usage: a replacement leaves it alone (' . json_encode($plan) . ')');
    Elements::restoreElement($outdated);
    check(isset($usages($glassRoof)['Station guide 2019:default']) && $model()->getCountFast([]) > $countBefore, 'restored: listed and counted again right away (its usages were kept)');
    Elements::deleteElement($outdated);
    check(!isset($usages($glassRoof)['Station guide 2019:default']) && $model()->getCountFast([]) === $countBefore, 'trashed again: out of the list again');

    // one image, many usages: several pages in the field's list; 30 notes per destination
    $notes = $asset('station-notes.jpg');
    $noteRows = $usages($notes);
    $noteIds = (new RelationsModel((int)$notes->id, (int)$notes->siteId))->getSourceElementIds();
    check(count($noteIds) === 90 && count($noteRows) === 90, 'demo: 90 station notes share one image, ' . count($noteRows) . ' rows in its list, one per note whatever its sites (pages of 20)');
    foreach (['Beijing', 'Hong Kong', 'Taipei'] as $city) {
        $notesThere = array_filter(array_keys($usages($entry('destinations', $city))), fn(string $row) => str_starts_with($row, 'Station notes:') && str_ends_with($row, ':default'));
        check(count($notesThere) === 30, "demo: $city has 30 station notes (" . count($notesThere) . ')');
    }
};
