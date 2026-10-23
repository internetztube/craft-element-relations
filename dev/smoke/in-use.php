<?php

declare(strict_types=1);

/**
 * Pro, the in-use guard's rules outside a request (the control panel parts are in dev/e2e.php and dev/ui.mts): which
 * elements it looks at, what it names, and that the console, migrations and Lite are never guarded.
 */

use CraftCms\Cms\Element\ElementCollection;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Drafts;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Plugins;
use CraftCms\Cms\Support\Facades\Sections;
use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Elements\DeletionBlockers\InUseDeletionBlocker;
use Internetztube\ElementRelations\Models\RelationsModel;
use Internetztube\ElementRelations\Services\InUseGuard;
use Internetztube\ElementRelations\Support\ActingUser;

use function CraftCms\Cms\t;

return function (array $ctx): void {
    $plugin = ElementRelations::instance() ?? throw new RuntimeException('plugin');
    $editionBefore = $plugin->edition;
    Plugins::switchEdition('element-relations', 'pro');
    $target = $ctx['target'];
    foreach (Entry::find()->title('In use:*')->status(null)->all() as $old) { // from an aborted run
        Elements::deleteElement($old, true);
    }
    check(InUseGuard::counts($target), 'a live element with usages counts');
    $unused = array_values(array_filter(Entry::find()->section('pages')->status(null)->all(), fn(Entry $e): bool => !(new RelationsModel((int)$e->id, (int)$e->siteId))->getIsInUseFast([])));
    check($unused && !InUseGuard::counts($unused[0]), 'an element nobody uses does not');
    $draft = Drafts::createDraft($target, (int)admin()->id, 'in-use check');
    check(!InUseGuard::counts($draft), 'a draft does not: it changes nothing visitors see');
    $propagating = Entry::find()->id($target->id)->one() ?? throw new RuntimeException('target');
    $propagating->propagating = true;
    check(!InUseGuard::counts($propagating), 'nor does a save that only propagates one already checked');
    Elements::deleteElement($draft, true);
    $nested = Entry::find()->status(null)->whereNotNull('entries.primaryOwnerId')->one() ?? throw new RuntimeException('no nested entry');
    $trashed = Entry::find()->id($target->id)->one() ?? throw new RuntimeException('target');
    $trashed->trashed = true;
    check(!InUseGuard::counts($nested) && !InUseGuard::counts($trashed), 'nor a nested entry (it goes with its owner) or a trashed one (checked when it was deleted)');

    // Craft's delete dialog: the usages of everything being deleted, the first few named, the rest counted
    // Page C's usages from the 4.x content (categories, tags, global sets, plugins) are gone on Craft 6: three live
    // entries make it "more than three"
    $section = Sections::getSectionByHandle('pages') ?? throw new RuntimeException('pages');
    $extra = [];
    foreach (['a', 'b', 'c'] as $n) {
        $entry = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => "In use: source $n"]);
        $entry->setFieldValue('relatedEntries', [$target->id]);
        Elements::saveElement($entry) or fail($entry);
        $extra[] = $entry;
    }
    flushQueue();
    $blocker = new InUseDeletionBlocker(new ElementCollection([$unused[0], $target]), false);
    $all = InUseGuard::liveUsages($target)['count'];
    check($blocker->isActive() && $all > 3 && str_contains($blocker->getSummary(), t('in-use-more', ['count' => $all - InUseGuard::NAMED + 1], 'element-relations')), "delete dialog: names two of the $all usages and counts the rest");
    foreach ($extra as $entry) {
        Elements::deleteElement($entry, true);
    }
    // used, but by nothing visitors see (a disabled entry): no blocker
    $offlineOnly = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => 'In use: offline only']);
    Elements::saveElement($offlineOnly) or fail($offlineOnly);
    $offlineSource = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => 'In use: disabled source', 'enabled' => false]);
    $offlineSource->setFieldValue('relatedEntries', [$offlineOnly->id]);
    Elements::saveElement($offlineSource) or fail($offlineSource);
    flushQueue();
    $offlineBlocker = new InUseDeletionBlocker(new ElementCollection([$offlineOnly]), false);
    check(InUseGuard::counts($offlineOnly) && !$offlineBlocker->isActive(), 'delete dialog: an element used only by a disabled entry is not in the way');
    // the setting switches the protection off: no blocker, everyone may delete
    $settings = ElementRelations::instance()?->getSettings() ?? throw new RuntimeException("settings"); // after switchEdition(): a new plugin instance
    $settings->protectInUse = false;
    $off = new InUseDeletionBlocker(new ElementCollection([$target]), false);
    check(!$off->isActive() && ElementRelations::mayDeleteInUse(), 'with “Protect elements in use” off: no blocker in the delete dialog, and deleting is allowed');
    $settings->protectInUse = true;
    Elements::deleteElement($offlineSource, true);
    Elements::deleteElement($offlineOnly, true);
    flushQueue();
    Plugins::switchEdition('element-relations', 'lite');
    check(!(new InUseDeletionBlocker(new ElementCollection([$target]), false))->isActive(), 'delete dialog, Lite: no blocker');
    Plugins::switchEdition('element-relations', 'pro');

    // what it names: elements with their sites (plugin settings: see extension-points.php)
    $asset = $ctx['asset'];
    $usages = InUseGuard::liveUsages($asset);
    check($usages['count'] > 0 && $usages['titles'] && $usages['count'] >= count($usages['titles']), 'the live usages are counted and named (' . $usages['count'] . ': ' . implode(', ', $usages['titles']) . ')');

    // never guarded: the console (scripts, migrations), and Lite
    check(!ElementRelations::guardsInUse() && ElementRelations::canReplace() && ElementRelations::canRollBack(), 'the console is never guarded and may replace and roll back');
    check(ActingUser::run(null, fn(): bool => ElementRelations::canReplace()), 'also without any user (a script, a migration)');
    Plugins::switchEdition('element-relations', 'lite');
    check(ElementRelations::mayDeleteInUse() && !ElementRelations::guardsInUse(), 'Lite: anyone may delete or disable elements in use');
    Plugins::switchEdition('element-relations', $editionBefore);
};
