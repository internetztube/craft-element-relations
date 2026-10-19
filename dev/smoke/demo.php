<?php
/**
 * The demo site (dev/demo.php): its content is there and reads the way screenshots show it, plus the Field column
 * regression found with it (two usage lists in one request mixed their fields).
 */

use craft\elements\Asset;
use craft\elements\Entry;
use internetztube\elementRelations\elements\Usage;

return function (array $ctx): void {
    $usages = function (craft\base\ElementInterface $of): array {
        $query = Usage::find()->allSites()->status(null)->drafts(null);
        Craft::configure($query, ['usesElement' => $of->id]);
        $rows = [];
        foreach ($query->all() as $e) {
            $rows["$e:{$e->getSite()->handle}" . ($e->getIsDraft() ? ':draft' : '')] = strip_tags($e->getAttributeHtml(Usage::ATTR_FIELD));
        }
        return $rows;
    };
    $skyline = Asset::find()->filename('train-and-city-towers.jpg')->one();
    $guide = Asset::find()->filename('rail-pass-guide-2026.pdf')->one();
    check($skyline && $guide, 'demo: images and documents exist');
    $skylineRows = $usages($skyline);
    check(($skylineRows['Homepage:default'] ?? null) === 'Hero image' && ($skylineRows['Startseite:de'] ?? null) === 'Hero image', 'demo: the skyline photo is the homepage hero, in both sites (' . json_encode($skylineRows) . ')');
    check(($skylineRows['How to read a station:default'] ?? null) === 'Body', 'demo: …embedded in an article body');
    $guideRows = $usages($guide);
    check(($guideRows['Homepage:default'] ?? null) === 'Link' && isset($guideRows['Footer:default'], $guideRows['Newsletter:default'], $guideRows["Steel, glass and a bird's nest:default"]), 'demo: the rail pass guide is used by the homepage link, the footer menu, a form and an article (' . json_encode($guideRows) . ')');

    $types = array_unique(array_map(fn(Entry $e) => $e->getType()->handle, Entry::find()->section('journal')->status(null)->all()));
    check(!array_diff(['article', 'photoEssay', 'route', 'review'], $types), 'demo: the journal has every entry type (' . implode(', ', $types) . ')');
    $logoRows = $usages(Asset::find()->filename('transit-logo.png')->one());
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
};
