<?php
/**
 * Pro, the in-use guard's rules outside a request (the control panel parts are in dev/e2e.php and dev/ui.mjs): which
 * elements it looks at, what it names, and that the console, migrations and Lite are never guarded.
 */

use craft\elements\Entry;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\models\RelationsModel;
use internetztube\elementRelations\services\InUseGuard;

return function (array $ctx): void {
    $plugins = Craft::$app->getPlugins();
    $editionBefore = ElementRelations::getInstance()->edition;
    $plugins->switchEdition('element-relations', 'pro');
    $target = $ctx['target'];
    check(InUseGuard::counts($target), 'a live element with usages counts');
    $unused = Entry::find()->section('pages')->status(null)->all();
    $unused = array_values(array_filter($unused, fn(Entry $e): bool => !(new RelationsModel((int)$e->id, (int)$e->siteId))->getIsInUseFast([])));
    check($unused && !InUseGuard::counts($unused[0]), 'an element nobody uses does not');
    $draft = Craft::$app->getDrafts()->createDraft($target, (int)craft\elements\User::find()->admin()->one()->id, 'in-use check');
    check(!InUseGuard::counts($draft), 'a draft does not: it changes nothing visitors see');
    $propagating = Entry::find()->id($target->id)->one();
    $propagating->propagating = true;
    check(!InUseGuard::counts($propagating), 'nor does a save that only propagates one already checked');
    Craft::$app->getElements()->deleteElement($draft, true);
    $nested = Entry::find()->status(null)->where(['not', ['entries.primaryOwnerId' => null]])->one() ?? throw new RuntimeException('no nested entry');
    $trashed = Entry::find()->id($target->id)->one();
    $trashed->trashed = true;
    check(!InUseGuard::counts($nested) && !InUseGuard::counts($trashed), 'nor a nested entry (it goes with its owner) or a trashed one (checked when it was deleted)');

    // Craft 5.10+'s delete dialog: the usages of everything being deleted, the first few named, the rest counted
    if (class_exists(craft\elements\deletionblockers\BaseDeletionBlocker::class)) {
        $blocker = new internetztube\elementRelations\elements\deletionblockers\InUseDeletionBlocker(new craft\elements\ElementCollection([$unused[0], $target]), false);
        $all = InUseGuard::liveUsages($target)['count'];
        check($blocker->isActive() && $all > 3 && str_contains($blocker->getSummary(), Craft::t('element-relations', 'in-use-more', ['count' => $all - InUseGuard::NAMED + 1])), "delete dialog: names two of the $all usages and counts the rest");
        // used, but by nothing visitors see (a disabled entry): no blocker
        $section = Craft::$app->getEntries()->getSectionByHandle('pages');
        $offlineOnly = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => 'In use: offline only']);
        Craft::$app->getElements()->saveElement($offlineOnly) or throw new RuntimeException(json_encode($offlineOnly->getErrors()));
        $offlineSource = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => 'In use: disabled source', 'enabled' => false]);
        $offlineSource->setFieldValue('relatedEntries', [$offlineOnly->id]);
        Craft::$app->getElements()->saveElement($offlineSource) or throw new RuntimeException(json_encode($offlineSource->getErrors()));
        flushQueue();
        $offlineBlocker = new internetztube\elementRelations\elements\deletionblockers\InUseDeletionBlocker(new craft\elements\ElementCollection([$offlineOnly]), false);
        check(InUseGuard::counts($offlineOnly) && !$offlineBlocker->isActive(), 'delete dialog: an element used only by a disabled entry is not in the way');
        // the setting switches the protection off: no blocker, everyone may delete
        $settings = internetztube\elementRelations\ElementRelations::$plugin->getSettings();
        $settings->protectInUse = false;
        $off = new internetztube\elementRelations\elements\deletionblockers\InUseDeletionBlocker(new craft\elements\ElementCollection([$target]), false);
        check(!$off->isActive() && internetztube\elementRelations\ElementRelations::mayDeleteInUse(), 'with “Protect elements in use” off: no blocker in the delete dialog, and deleting is allowed');
        $settings->protectInUse = true;
        Craft::$app->getElements()->deleteElement($offlineSource, true);
        Craft::$app->getElements()->deleteElement($offlineOnly, true);
        flushQueue();
        $plugins->switchEdition('element-relations', 'lite');
        check(!(new internetztube\elementRelations\elements\deletionblockers\InUseDeletionBlocker(new craft\elements\ElementCollection([$target]), false))->isActive(), 'delete dialog, Lite: no blocker');
        $plugins->switchEdition('element-relations', 'pro');
    }

    // what it names: elements with their sites, and plugin settings (here SEOmatic's global image, see seed.php)
    $asset = $ctx['asset'];
    $usages = InUseGuard::liveUsages($asset);
    check($usages['count'] > 0 && $usages['titles'] && $usages['count'] >= count($usages['titles']), 'the live usages are counted and named (' . $usages['count'] . ': ' . implode(', ', $usages['titles']) . ')');
    $globals = (new RelationsModel((int)$asset->id, (int)$asset->siteId))->getGlobalUsages();
    check($globals && (bool)array_filter($usages['titles'], fn(string $t): bool => str_contains($t, $globals[0]->label)) || $usages['count'] > count($usages['titles']), 'plugin settings are among them (' . ($globals[0]->label ?? 'none') . ')');

    // never guarded: the console (scripts, migrations), and Lite
    check(!ElementRelations::guardsInUse() && ElementRelations::canReplace() && ElementRelations::canRollBack(), 'the console is never guarded and may replace and roll back');
    $session = Craft::$app->getUser();
    $identity = $session->getIdentity();
    $session->setIdentity(null);
    check(ElementRelations::canReplace(), 'also without any user (a script, a migration)');
    $session->setIdentity($identity);
    $plugins->switchEdition('element-relations', 'lite');
    check(ElementRelations::mayDeleteInUse() && !ElementRelations::guardsInUse(), 'Lite: anyone may delete or disable elements in use');
    $plugins->switchEdition('element-relations', $editionBefore);
};
