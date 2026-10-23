<?php
/**
 * The demo site (dev/demo.php): its content is there and reads the way screenshots show it, plus the Field column
 * regression found with it (two usage lists in one request mixed their fields).
 */

use craft\elements\Asset;
use craft\elements\Entry;
use internetztube\elementRelations\elements\Usage;
use internetztube\elementRelations\models\RelationsModel;

return function (array $ctx): void {
    // one row per element (in the site it is shown in): "title:site[:draft]" => its Field column, fields sorted
    // (their order differs between databases); $siteRows: the same keys => the Site column
    $siteRows = [];
    $usages = function (craft\base\ElementInterface $of) use (&$siteRows): array {
        $query = Usage::find()->allSites()->status(null)->drafts(null);
        Craft::configure($query, ['usesElement' => $of->id]);
        $rows = [];
        foreach ($query->all() as $e) {
            $fields = explode(', ', strip_tags($e->getAttributeHtml(Usage::ATTR_FIELD)));
            sort($fields);
            $key = "$e:{$e->getSite()->handle}" . ($e->getIsDraft() ? ':draft' : '');
            $rows[$key] = implode(', ', $fields);
            $siteRows[$key] = strip_tags($e->getAttributeHtml(Usage::ATTR_SITE));
        }
        return $rows;
    };
    $skyline = Asset::find()->filename('train-and-city-towers.jpg')->one();
    $guide = Asset::find()->filename('rail-pass-guide-2026.pdf')->one();
    check($skyline && $guide, 'demo: images and documents exist');
    $skylineRows = $usages($skyline);
    check(($skylineRows['Homepage:default'] ?? null) === 'Hero image' && !isset($skylineRows['Startseite:de']) && ($siteRows['Homepage:default'] ?? null) === 'English, German', 'demo: the skyline photo is the homepage hero, listed once with both sites (' . json_encode($skylineRows) . ')');
    check(($skylineRows['How to read a station:default'] ?? null) === 'Body', 'demo: …embedded in an article body');
    $guideRows = $usages($guide);
    check(($guideRows['Homepage:default'] ?? null) === 'Link' && isset($guideRows['Footer:default'], $guideRows['Newsletter:default'], $guideRows["Steel, glass and a bird's nest:default"]), 'demo: the rail pass guide is used by the homepage link, the footer menu, a form and an article (' . json_encode($guideRows) . ')');

    $types = array_unique(array_map(fn(Entry $e) => $e->getType()->handle, Entry::find()->section('journal')->status(null)->all()));
    check(!array_diff(['article', 'photoEssay', 'route', 'review'], $types), 'demo: the journal has every entry type (' . implode(', ', $types) . ')');
    $logoRows = $usages(Asset::find()->filename('transit-logo.svg')->one());
    check(($logoRows['Transit tote bag:default'] ?? null) === 'Image' && ($logoRows['Site settings:default'] ?? null) === 'Logo', 'demo: the logo is used by a product and the global set (' . json_encode($logoRows) . ')');
    $hk = Entry::find()->section('destinations')->title('Hong Kong')->one();
    check(($usages($hk)['Beijing to Hong Kong in one day:default'] ?? null) === 'Destinations', 'demo: a route stop (Matrix) uses a destination, listed as its route');

    // two lists in a row: the second one shows only its own fields
    $jonas = Entry::find()->section('team')->title('Jonas Berger')->one();
    $rails = Entry::find()->section('journal')->title('Twelve hours on the rails')->one();
    $usages($rails);
    $jonasRows = $usages($jonas);
    check(($jonasRows['Packing light for a week of trains:default'] ?? null) === 'Written by', 'Field column: only the field linking this element, not the fields of an earlier list in the request (' . ($jonasRows['Packing light for a week of trains:default'] ?? '-') . ')');
    check(isset($jonasRows['Why we take the train:default:draft']), 'demo: the draft of an article shows up as a usage');

    // a never-published article (only a draft)
    $river = Asset::find()->filename('afternoon-by-the-river.jpg')->one();
    check(isset($usages($river)['A ferry to Busan:default:draft']), 'demo: an unpublished draft shows up as a usage (a draft row)');

    // front-end defaults: what visitors may see unless asked for; an explicit null means everything (as in 3.1)
    $asVisitor = function (callable $fn): mixed {
        $request = Craft::$app->getRequest();
        Craft::$app->set('request', ['class' => craft\web\Request::class, 'isCpRequest' => false, 'cookieValidationKey' => Craft::$app->getConfig()->getGeneral()->securityKey, 'url' => '/']);
        try {
            return $fn();
        } finally {
            Craft::$app->set('request', $request);
        }
    };
    $riverModel = new RelationsModel($river->id, $river->siteId);
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
    $glassRoof = Asset::find()->filename('glass-roof.jpg')->one();
    $outdated = Entry::find()->title('Station guide 2019')->trashed(true)->status(null)->one();
    check($outdated !== null && Asset::find()->filename('timetable-2019.pdf')->trashed(true)->exists(), 'demo: an article and a document are in the trash');
    $trashedUsages = fn() => array_map('strval', Craft::configure(Usage::find()->allSites()->status(null)->trashed(true), ['usesElement' => $glassRoof->id])->all());
    $model = fn() => new RelationsModel($glassRoof->id, $glassRoof->siteId);
    $countBefore = $model()->getCountFast([]);
    check(!isset($usages($glassRoof)['Station guide 2019:default']) && in_array('Station guide 2019', $trashedUsages(), true), 'trashed usage: not in the list, but under "Trashed"');
    check(!in_array($outdated->id, $model()->getSourceElementIds(), true), "trashed usage: not counted ($countBefore)");
    $plan = internetztube\elementRelations\services\ReplaceService::replace($glassRoof, Asset::find()->filename('into-the-tunnel.jpg')->one(), [$outdated->id]);
    check($plan['replaced'] === 0, 'trashed usage: a replacement leaves it alone (' . json_encode($plan) . ')');
    Craft::$app->getElements()->restoreElement($outdated);
    check(isset($usages($glassRoof)['Station guide 2019:default']) && $model()->getCountFast([]) > $countBefore, 'restored: listed and counted again right away (its usages were kept)');
    Craft::$app->getElements()->deleteElement($outdated);
    check(!isset($usages($glassRoof)['Station guide 2019:default']) && $model()->getCountFast([]) === $countBefore, 'trashed again: out of the list again');

    // one image, many usages: several pages in the field's list; 30 notes per destination
    $notes = Asset::find()->filename('station-notes.jpg')->one();
    $noteRows = $usages($notes);
    $noteIds = (new RelationsModel($notes->id, $notes->siteId))->getSourceElementIds();
    check(count($noteIds) === 90 && count($noteRows) === 90, 'demo: 90 station notes share one image, ' . count($noteRows) . ' rows in its list, one per note whatever its sites (pages of 20)');
    foreach (['Beijing', 'Hong Kong', 'Taipei'] as $city) {
        $destination = Entry::find()->section('destinations')->title($city)->one();
        $notesThere = array_filter(array_keys($usages($destination)), fn(string $row) => str_starts_with($row, 'Station notes:') && str_ends_with($row, ':default'));
        check(count($notesThere) === 30, "demo: $city has 30 station notes (" . count($notesThere) . ')');
    }
};
