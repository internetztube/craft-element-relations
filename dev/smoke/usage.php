<?php
/**
 * Live usages: the `usage()` query param (live, any, notLive, none), the "Usage" filter rule (rules saved as the old
 * "Used anywhere" switch keep their meaning), and the field's "Used in (live)" column with its sort.
 */

use craft\elements\Entry;
use internetztube\elementRelations\elements\conditions\UsedConditionRule;
use internetztube\elementRelations\fields\ElementRelationsField;
use internetztube\elementRelations\models\RelationsModel;

return function (array $ctx): void {
    $elements = Craft::$app->getElements();
    $section = Craft::$app->getEntries()->getSectionByHandle('pages');
    $made = [];
    $make = function (string $title, array $values = [], array $attributes = []) use ($section, $elements, &$made): Entry {
        $entry = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => "Usage: $title"] + $attributes);
        $entry->setFieldValues($values);
        $elements->saveElement($entry) or throw new RuntimeException(json_encode($entry->getErrors()));
        return $made[] = $entry;
    };
    // what uses them: a live entry, a disabled one, one posted tomorrow, an unpublished draft; and nothing
    $live = $make('used live');
    $offline = $make('used by a disabled entry');
    $pending = $make('used by a pending entry');
    $drafted = $make('used by a draft');
    $unused = $make('unused');
    $liveSource = $make('live source', ['relatedEntries' => [$live->id]]);
    $make('disabled source', ['relatedEntries' => [$offline->id]], ['enabled' => false]);
    $make('pending source', ['relatedEntries' => [$pending->id]], ['postDate' => new DateTime('+1 day')]);
    $draft = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => 'Usage: draft source']);
    $draft->setFieldValue('relatedEntries', [$drafted->id]);
    Craft::$app->getDrafts()->saveElementAsDraft($draft, null) or throw new RuntimeException(json_encode($draft->getErrors()));
    flushQueue();
    $targets = [$live->id, $offline->id, $pending->id, $drafted->id, $unused->id];
    $ids = function (array $params) use ($targets): array {
        $query = Entry::find()->id($targets)->status(null);
        Craft::configure($query, $params);
        $ids = array_map('intval', $query->ids());
        sort($ids);
        return $ids;
    };
    $sorted = function (array $ids): array {
        sort($ids);
        return $ids;
    };
    try {
        check($ids(['usage' => 'live']) === [$live->id], 'usage("live"): only what a live entry uses');
        check($ids(['usage' => 'notLive']) === $sorted([$offline->id, $pending->id, $drafted->id]), 'usage("notLive"): what only a disabled entry, a pending entry or a draft uses');
        check($ids(['usage' => 'any']) === $sorted([$live->id, $offline->id, $pending->id, $drafted->id]) && $ids(['unused' => false]) === $ids(['usage' => 'any']), 'usage("any") (and unused(false)): everything something uses, in any status');
        check($ids(['usage' => 'none']) === [$unused->id] && $ids(['unused' => true]) === [$unused->id], 'usage("none") (and unused()): only what nothing uses');
        check(array_map('intval', Entry::find()->id($targets)->status(null)->usage('none')->ids()) === [$unused->id], 'craft.entries().usage("none") as a template calls it');

        // the filter rule; rules saved as the on/off "Used anywhere" mean "any" (on) and "none" (off)
        $rule = fn(array $config): UsedConditionRule => new UsedConditionRule($config);
        check($rule([])->value === 'live' && $rule(['value' => true])->value === 'any' && $rule(['value' => false])->value === 'none' && $rule(['value' => 'notLive'])->value === 'notLive', 'the Usage rule starts at "Used live"; saved "Used anywhere" rules keep their meaning (on: any status, off: unused)');
        $query = Entry::find()->id($targets)->status(null);
        $rule(['value' => 'notLive'])->modifyQuery($query);
        $filtered = array_map('intval', $query->ids());
        sort($filtered);
        check($filtered === $sorted([$offline->id, $pending->id, $drafted->id]) && $rule([])->matchElement($live) && !$rule([])->matchElement($offline), 'the rule filters by usage and matches an element by it');
        check(str_contains(withWebRequest(fn() => $rule([])->getHtml()), 'Not used live'), 'it offers Used live, Used (any status), Not used live and Unused');

        // the "Used in (live)" column, in the View menu of the types that have the field
        $field = Craft::$app->getFields()->getFieldByHandle('relations');
        $live_ = $field->getLiveAttribute();
        check(in_array((int)$field->id, array_map(fn($f) => (int)$f->id, ElementRelationsField::forType(Entry::class)), true) && ElementRelationsField::forType(craft\elements\User::class) === [] && ElementRelationsField::forLiveAttribute($live_)?->id === $field->id && ElementRelationsField::forLiveAttribute('title') === null, 'the field is found in the entries\' layouts (not the users\'), and by its live column');
        check((Entry::tableAttributes()[$live_]['label'] ?? null) === 'Relations (live)' && in_array($live_, array_column(Entry::sortOptions(), 'attribute'), true), 'entries offer the column "Relations (live)" and a sort by it, one per field');
        $html = fn(Entry $entry): string => withWebRequest(fn() => (string)$entry->getAttributeHtml($live_));
        check(str_contains($html($live), 'Usage: live source') && str_contains($html($offline), 'Not used live') && str_contains($html($drafted), 'Not used live') && !str_contains($html($unused), 'Not used live'), 'the column: the live usages; "Not used live" when only offline entries or drafts use it; unused stays "Unused"');
        RelationsModel::forgetPrefetched();
        RelationsModel::prefetch($targets, 5, (int)$live->siteId, live: true);
        $model = fn(Entry $entry): RelationsModel => (new RelationsModel((int)$entry->id, (int)$entry->siteId))->forTargetSite();
        check($model($live)->getLiveCountFast() === 1 && $model($offline)->getLiveCountFast() === 0 && $model($live)->getLivePreviewElements(5)[0]->id === $liveSource->id, 'prefetched for the page: live counts and the first live usages');
        RelationsModel::forgetPrefetched();
        check((new RelationsModel((int)$live->id, (int)$live->siteId))->getLiveCountFast() === 1, 'and loaded per element when nothing was prefetched');

        // its sort: live usages first
        $order = $field->getSortOption(live: true);
        $byLive = array_map('intval', Entry::find()->id($targets)->status(null)->orderBy([($order['orderBy'])(SORT_DESC), 'elements.id' => SORT_ASC])->ids());
        check($order['attribute'] === $live_ && $byLive[0] === $live->id, 'sorting by "Relations (live)" puts what a live entry uses first');
    } finally {
        foreach ([$draft, ...array_reverse($made)] as $entry) {
            $elements->deleteElement($entry, true);
        }
    }
};
