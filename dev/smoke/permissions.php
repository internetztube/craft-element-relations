<?php
/**
 * Permissions: what non-admin control panel users see in the usage list and what they may replace and roll back.
 * Craft Solo has a single user and no permissions, so the edition is lifted to Pro in memory for this file.
 * Creates a section "ER Secret" with one entry using Page C, and two users; everything is deleted again.
 */

use craft\elements\Entry;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\elements\RestrictedUsage;
use internetztube\elementRelations\elements\Usage;
use internetztube\elementRelations\records\ReplacementRecord;
use internetztube\elementRelations\services\ReplaceService;

return function (array $ctx): void {
    $app = Craft::$app;
    $elements = $app->getElements();
    $entriesService = $app->getEntries();
    $plugins = $app->getPlugins();
    $editionBefore = ElementRelations::getInstance()->edition;
    $craftEditionBefore = $app->edition;
    $identityBefore = $app->getUser()->getIdentity();
    $app->edition = CmsEdition::Pro;
    $plugins->switchEdition('element-relations', 'pro');
    $flush = function (): void {
        Craft::$app->trigger(yii\base\Application::EVENT_AFTER_REQUEST);
        Craft::$app->getQueue()->run();
    };

    // --- fixtures
    foreach (User::find()->username(['er-viewer-a', 'er-viewer-b'])->status(null)->all() as $old) {
        $elements->deleteElement($old, true);
    }
    if ($old = $entriesService->getSectionByHandle('erSecret')) {
        $entriesService->deleteSection($old);
    }
    $pages = $entriesService->getSectionByHandle('pages');
    $pageType = $pages->getEntryTypes()[0];
    $secret = new Section([
        'name' => 'ER Secret',
        'handle' => 'erSecret',
        'type' => Section::TYPE_CHANNEL,
        'enableVersioning' => false, // also the "versioning off" case for revisions
        'siteSettings' => array_map(fn($site) => new Section_SiteSettings(['siteId' => $site->id, 'hasUrls' => false]), $app->getSites()->getAllSites()),
    ]);
    $secret->setEntryTypes([$pageType]);
    $entriesService->saveSection($secret) or throw new RuntimeException(json_encode($secret->getErrors()));
    $c = $ctx['target'];
    $secretEntry = new Entry(['sectionId' => $secret->id, 'typeId' => $pageType->id, 'title' => 'ER Secret source']);
    $secretEntry->setFieldValue('relatedEntries', [$c->id]);
    $elements->saveElement($secretEntry) or throw new RuntimeException(json_encode($secretEntry->getErrors()));
    $flush();
    check(in_array('ER Secret source', relatingTitles($c), true), 'fixture: the secret entry relates to Page C');

    $siteScopes = array_map(fn($site) => "editsite:$site->uid", $app->getSites()->getAllSites());
    $makeUser = function (string $username, array $permissions) use ($elements, $app): User {
        $user = new User(['username' => $username, 'email' => "$username@example.com"]);
        $elements->saveElement($user) or throw new RuntimeException(json_encode($user->getErrors()));
        $app->getUserPermissions()->saveUserPermissions($user->id, $permissions);
        return User::find()->id($user->id)->status(null)->one();
    };
    $permissionsA = ['accesscp', "viewentries:$pages->uid", "viewpeerentries:$pages->uid", ...$siteScopes, ElementRelations::PERMISSION_REPLACE, ElementRelations::PERMISSION_ROLLBACK, "saveentries:$pages->uid", "savepeerentries:$pages->uid"];
    $viewerA = $makeUser('er-viewer-a', $permissionsA);
    $viewerB = $makeUser('er-viewer-b', ['accesscp', "viewentries:$pages->uid", ...$siteScopes]);
    check($viewerA->can("viewEntries:$pages->uid") && !$viewerA->can("viewEntries:$secret->uid") && !$viewerA->can('viewUsers'), 'fixture: viewer A may view pages (incl. peers), not the secret section, not users');

    $usages = function (Craft\base\ElementInterface $of, ?User $as, ?string $search = null): array {
        $query = Usage::find()->allSites()->status(null)->drafts(null)->viewableBy($as ?? false);
        Craft::configure($query, ['usesElement' => $of->id]);
        if ($search) {
            $query->search($search);
        }
        return ['count' => (int)$query->count(), 'elements' => $query->all()];
    };
    $titles = fn(array $list) => array_map(fn($e) => $e instanceof RestrictedUsage ? 'RESTRICTED:' . $e->realType : (string)$e, $list);

    // --- the usage list
    $admin = $usages($c, null);
    $a = $usages($c, $viewerA);
    check(in_array('ER Secret source', $titles($admin['elements']), true), 'without restriction the secret entry is listed');
    check(!in_array('ER Secret source', $titles($a['elements']), true) && !array_filter($a['elements'], fn($e) => $e->id === $secretEntry->id), 'viewer A: the entry from the section they cannot view is not listed at all');
    check($a['count'] === count($a['elements']), "viewer A: count matches the listed rows ({$a['count']})");
    check(in_array('Page via Matrix', $titles($a['elements']), true), 'viewer A: entries from pages are listed with their titles');
    check($usages($c, $viewerA, 'Secret')['count'] === 0, 'viewer A: searching for the secret title finds nothing');
    check(!array_filter($a['elements'], fn($e) => $e instanceof RestrictedUsage), 'viewer A: nothing they may not view is listed, not even as a placeholder');
    check(!array_filter($a['elements'], fn($e) => !$elements->canView($e, $viewerA)), 'viewer A: every listed element passes Craft\'s canView()');
    $plain = array_map(fn($e) => $e::class, $admin['elements']);
    check(in_array('verbb\\formie\\elements\\Form', $plain, true) && !array_filter($a['elements'], fn($e) => str_starts_with($e::class, 'verbb\\')), 'viewer A: plugin elements they may not view (Formie, Navigation, Wishlist) are left out');
    $submissionTitle = (string)(verbb\formie\elements\Submission::find()->status(null)->one() ?? '');
    check($submissionTitle === '' || $usages($c, $viewerA, $submissionTitle)['count'] === 0, "viewer A: searching for a Formie submission's title finds nothing (no existence oracle)");
    $boundParams = function (?User $as) use ($c): int {
        $query = Usage::find()->allSites()->status(null)->drafts(null)->viewableBy($as ?? false);
        Craft::configure($query, ['usesElement' => $c->id]);
        return count($query->createCommand()->params);
    };
    check($boundParams($viewerA) - $boundParams(null) <= count($app->getSites()->getAllSiteIds()), "viewer A: the elements they may view are written into the SQL, not bound one parameter each (PostgreSQL takes at most 65,535; dev/stress.php lists 100,000)");
    check((int)(new craft\db\Query())->from(craft\db\Table::ELEMENTS)->where(ElementRelations::idIn('id', range(1, 70000)))->count() > 0, 'a list of 70,000 ids runs as one query');
    check(\internetztube\elementRelations\elements\db\UsageQuery::hiddenCount([$c->id], $viewerA) === $admin['count'] - $a['count'], 'hiddenCount() is exactly what is left out (' . ($admin['count'] - $a['count']) . ')');

    // what a user may view is cached; it follows every change
    $usageQuery = \internetztube\elementRelations\elements\db\UsageQuery::class;
    // permissions saved for a user (Craft's permissions screen saves no element): "other authors' entries" is decided per
    // element, so it is cached; a target viewer A may still view (the secret entry, with its section's permissions)
    $peerSource = new Entry(['sectionId' => $pages->id, 'typeId' => $pageType->id, 'title' => 'ER Peer source']);
    $peerSource->setFieldValue('relatedEntries', [$secretEntry->id]);
    $elements->saveElement($peerSource) or throw new RuntimeException(json_encode($peerSource->getErrors()));
    $flush();
    $withSecret = [...$permissionsA, "viewentries:$secret->uid", "viewpeerentries:$secret->uid"];
    $app->getUserPermissions()->saveUserPermissions($viewerA->id, $withSecret);
    $granted = $usages($secretEntry, $viewerA);
    $app->getUserPermissions()->saveUserPermissions($viewerA->id, array_values(array_diff($withSecret, ["viewpeerentries:$pages->uid"])));
    $revoked = $usages($secretEntry, $viewerA);
    $app->getUserPermissions()->saveUserPermissions($viewerA->id, $permissionsA);
    $elements->deleteElement($peerSource, true);
    $flush();
    check($titles($granted['elements']) === ['ER Peer source'] && $revoked['count'] === 0 && $revoked['elements'] === [], 'viewer A: permissions saved for the user apply to the list right away (another author\'s entry is left out once they may no longer view those: ' . json_encode($titles($revoked['elements'])) . ')');
    $matrixPage = Entry::find()->title('Page via Matrix')->one();
    $deny = function (craft\events\AuthorizationCheckEvent $event) use ($matrixPage, $viewerA): void {
        if ((int)$event->element?->id === (int)$matrixPage->id && (int)$event->user->id === (int)$viewerA->id) {
            $event->authorized = false;
        }
    };
    $usages($c, $viewerA); // what viewer A may view, cached
    yii\base\Event::on(craft\services\Elements::class, craft\services\Elements::EVENT_AUTHORIZE_VIEW, $deny);
    $stale = $usages($c, $viewerA); // a plugin's view rule that changed without any event
    $usageQuery::resetPermissionCache();
    $fresh = $usages($c, $viewerA);
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_AUTHORIZE_VIEW, $deny);
    $usageQuery::resetPermissionCache();
    check(!in_array('Page via Matrix', $titles($stale['elements']), true) && in_array('RESTRICTED:' . Entry::class, $titles($stale['elements']), true), 'viewer A: a view rule that changed without an event: until the cache is reset the list shows a placeholder in its place, never the element');
    check($fresh['count'] === $a['count'] - 1 && !in_array('Page via Matrix', $titles($fresh['elements']), true) && !array_filter($fresh['elements'], fn($e) => $e instanceof RestrictedUsage), "viewer A: …once the cache is reset, it is left out ({$fresh['count']} of {$a['count']})");
    $cache = $app->getCache();
    $app->set('cache', null);
    $usageQuery::resetPermissionCache();
    $noCache = $usages($c, $viewerA);
    $app->set('cache', $cache);
    check($titles($noCache['elements']) === $titles($a['elements']) && $noCache['count'] === $a['count'], "viewer A: without a data cache configured, the same list ({$noCache['count']})");

    // a restricted user cannot switch the restriction off or list arbitrary elements
    try {
        Craft::configure(Usage::find(), ['viewableBy' => false]); // what element index request criteria would do
        check(false, 'criteria cannot set viewableBy');
    } catch (yii\base\UnknownPropertyException) {
        check(true, 'request criteria cannot switch the permission filter off (viewableBy is not configurable)');
    }
    check((int)Usage::find()->allSites()->status(null)->drafts(null)->viewableBy($viewerA)->count() === 0, 'viewer A: without usesElement the list is empty (no listing of everything)');
    check($usages($secretEntry, $viewerA)['count'] === 0, 'viewer A: no usages of an element they may not view');

    $b = $usages($c, $viewerB);
    check(!array_filter($b['elements'], fn($e) => $e->id === Entry::find()->title('Page via Matrix')->one()->id), 'viewer B (no peer entries): other authors\' entries in pages are left out');
    check(!in_array('ER Secret source', $titles($b['elements']), true), 'viewer B: the secret entry is not listed');

    $assetUsages = $usages($ctx['asset'], $viewerA);
    check(!array_filter($assetUsages['elements'], fn($e) => $e instanceof User), 'viewer A (no "view users"): the admin whose photo is the asset is not listed');

    // --- the field (index column preview, list, type dropdown) as viewer A in a CP request
    $field = Craft::$app->getFields()->getFieldByHandle('relations');
    $asViewer = function (callable $fn) use ($viewerA) {
        return withWebRequest(function () use ($fn, $viewerA) {
            Craft::$app->getUser()->setIdentity($viewerA);
            return $fn();
        });
    };
    $preview = $asViewer(fn() => $field->getPreviewHtml($c->getFieldValue('relations'), $c));
    check(!str_contains($preview, 'ER Secret source'), 'viewer A: the index column preview does not show the secret entry');
    $previewAdmin = withWebRequest(fn() => $field->getPreviewHtml($c->getFieldValue('relations'), $c));
    check(!str_contains(strip_tags($preview), 'Contact') || str_contains(strip_tags($previewAdmin), 'Contact'), 'viewer A: preview hides chips of plugin elements they may not view');
    $input = $asViewer(fn() => $field->getInputHtml($c->getFieldValue('relations'), $c));
    check(str_contains($input, 'data-element-relations-hidden="' . ($admin['count'] - $a['count']) . '"') && !str_contains($input, 'ER Secret source'), 'viewer A: the field says how many usages they may not see (so nobody takes the element for unused)');
    check(str_contains($preview, 'more you cannot view'), 'viewer A: the index column preview mentions the hidden usages too');
    $hint = fn(?array $ids) => internetztube\elementRelations\fields\ElementRelationsField::revisionHintFor($c, $ids);
    check($asViewer(fn() => $hint(null)) === $hint(array_values(array_map(fn($e) => (int)$e->id, $a['elements']))), "viewer A: the wizard's revision hint for all usages counts only the ones they may replace in");
    $export = $asViewer(fn() => $field->serializeValue($c->getFieldValue('relations'), $c));
    check(!in_array($secretEntry->id, $export['elementIds'], true) && $export['count'] === count($export['elementIds']), 'viewer A: exports leave out what they may not view');
    // the type dropdown counts are computed per type with the same restriction
    $restrictedCount = (int)(function () use ($c, $viewerA) {
        $q = Usage::find()->allSites()->status(null)->drafts(null)->elementTypes(Entry::class)->viewableBy($viewerA);
        Craft::configure($q, ['usesElement' => $c->id]);
        return $q->count();
    })();
    $plainCount = (int)(function () use ($c) {
        $q = Usage::find()->allSites()->status(null)->drafts(null)->elementTypes(Entry::class)->viewableBy(false);
        Craft::configure($q, ['usesElement' => $c->id]);
        return $q->count();
    })();
    $expected = (function () use ($c, $viewerA, $elements) {
        $q = Usage::find()->allSites()->status(null)->drafts(null)->elementTypes(Entry::class)->viewableBy(false);
        Craft::configure($q, ['usesElement' => $c->id]);
        return count(array_filter($q->all(), fn($e) => $elements->canView($e, $viewerA))); // every row, judged by Craft
    })();
    check($restrictedCount === $expected && $restrictedCount < $plainCount, "viewer A: the Entries count in the type dropdown is exactly the rows Craft lets them view ($restrictedCount of $plainCount)");

    // --- replace as viewer A
    $section = $pages;
    $target = new Entry(['sectionId' => $section->id, 'typeId' => $pageType->id, 'title' => 'ER Perm target']);
    $elements->saveElement($target) or throw new RuntimeException(json_encode($target->getErrors()));
    $app->getUser()->setIdentity($viewerA);
    $denied = ReplaceService::replace($c, $secretEntry, [$secretEntry->id]); // `to` they may not view (and different type check passes: both entries)
    check($denied['denied'] === true && $denied['operationId'] === null, 'viewer A cannot use an element they may not view as the replacement');
    $r = ReplaceService::replace($c, $target, [$secretEntry->id]);
    check($r['replaced'] === 0 && !$r['operationId'] && $r['skipped'] && !str_contains(implode(' ', $r['skipped']), 'Secret'), 'viewer A cannot change the secret entry, and the message does not name it (' . implode(' | ', $r['skipped']) . ')');
    $flush();
    check(in_array('ER Secret source', relatingTitles($c), true), '…the secret entry still uses Page C');

    check(ReplaceService::replace($c, $target, [])['replaced'] === 0 && in_array('ER Secret source', relatingTitles($c), true), 'an empty selection replaces nothing (never "everywhere")');

    // a field that only admins may edit (field layout edit condition) is not touched for viewer A
    $app->getUser()->setIdentity($identityBefore);
    $lockedType = new craft\models\EntryType(['name' => 'ER Locked', 'handle' => 'erLocked']);
    $lockedLayout = new craft\models\FieldLayout(['type' => Entry::class]);
    $lockedTab = new craft\models\FieldLayoutTab(['name' => 'Content', 'layout' => $lockedLayout]);
    $lockedField = new craft\fieldlayoutelements\CustomField(Craft::$app->getFields()->getFieldByHandle('relatedEntries'));
    $lockedField->setEditCondition(['class' => craft\elements\conditions\users\UserCondition::class, 'conditionRules' => [['class' => craft\elements\conditions\users\AdminConditionRule::class, 'value' => true]]]);
    $lockedTab->setElements([new craft\fieldlayoutelements\entries\EntryTitleField(), $lockedField]);
    $lockedLayout->setTabs([$lockedTab]);
    $lockedType->setFieldLayout($lockedLayout);
    $entriesService->saveEntryType($lockedType) or throw new RuntimeException(json_encode($lockedType->getErrors()));
    $pages->setEntryTypes([...$pages->getEntryTypes(), $lockedType]);
    $entriesService->saveSection($pages) or throw new RuntimeException(json_encode($pages->getErrors()));
    $locked = new Entry(['sectionId' => $pages->id, 'typeId' => $lockedType->id, 'title' => 'ER Locked source']);
    $locked->setFieldValue('relatedEntries', [$c->id]);
    $elements->saveElement($locked) or throw new RuntimeException(json_encode($locked->getErrors()));
    $flush();
    $app->getUser()->setIdentity($viewerA);
    $lockedResult = ReplaceService::replace($c, $target, [$locked->id]);
    $flush();
    check($lockedResult['replaced'] === 0 && in_array('ER Locked source', relatingTitles($c), true) && str_contains(implode(' ', $lockedResult['skipped']), 'no permission'), 'viewer A cannot change a field that only admins may edit (' . implode(' | ', $lockedResult['skipped']) . ')');
    $app->getUser()->setIdentity($identityBefore);
    $adminLocked = ReplaceService::replace($c, $target, [$locked->id]);
    $flush();
    check($adminLocked['replaced'] === 1, 'an admin can');
    $app->getUser()->setIdentity($viewerA);
    $lockedBack = ReplaceService::rollback(ReplacementRecord::findOne($adminLocked['operationId']));
    $app->getUser()->setIdentity($identityBefore);
    $flush();
    check($lockedBack['restored'] === 0 && str_contains(implode(' ', $lockedBack['skipped']), 'no permission') && !in_array('ER Locked source', relatingTitles($c), true), "viewer A cannot roll it back either: the field is left as it is (" . implode(' | ', $lockedBack['skipped']) . ')');
    ReplacementRecord::findOne($adminLocked['operationId'])?->delete();
    $elements->deleteElement($locked, true);
    $pages->setEntryTypes(array_values(array_filter($pages->getEntryTypes(), fn($t) => $t->id !== $lockedType->id)));
    $entriesService->saveSection($pages);
    $entriesService->deleteEntryType($lockedType);
    $app->getUser()->setIdentity($viewerA);

    $own = new Entry(['sectionId' => $section->id, 'typeId' => $pageType->id, 'title' => 'ER Perm source']);
    $own->setFieldValue('relatedEntries', [$c->id]);
    $app->getUser()->setIdentity($identityBefore);
    $elements->saveElement($own) or throw new RuntimeException(json_encode($own->getErrors()));
    $flush();
    $app->getUser()->setIdentity($viewerA);
    $mine = ReplaceService::replace($c, $target, [$own->id]);
    $flush();
    check($mine['replaced'] === 1 && $mine['operationId'], 'viewer A can replace in an entry they may save');
    $mineOp = ReplacementRecord::findOne($mine['operationId']);
    check((int)$mineOp->userId === (int)$viewerA->id, 'the operation records viewer A as its user');

    // --- rollback ownership
    $app->getUser()->setIdentity($identityBefore);
    $adminOp = ReplaceService::replace($target, $c, [$own->id]); // the admin swaps it back with a new operation
    $flush();
    $adminOpRecord = ReplacementRecord::findOne($adminOp['operationId']);
    check(!ElementRelations::canRollBackOperation($adminOpRecord, $viewerA), 'viewer A cannot roll back the admin\'s replacement');
    check(ElementRelations::canRollBackOperation($mineOp, $viewerA), 'viewer A can roll back their own replacement');
    check(ElementRelations::canRollBackOperation($mineOp, User::find()->username('admin')->one()), 'admins can roll back any replacement');
    check(!ElementRelations::canRollBackOperation($mineOp, $viewerB), 'a user without the rollback permission cannot');
    check(!ElementRelations::canReplace($viewerB), 'a user without the replace permission cannot replace');

    // --- queued replacements fail closed when their user is gone or may no longer replace
    $app->getUser()->setIdentity($identityBefore);
    $ghost = $makeUser('er-viewer-ghost', ['accesscp', ...$siteScopes, ElementRelations::PERMISSION_REPLACE]);
    foreach ([[$viewerB->id, 'a user without the replace permission'], [$ghost->id, 'a deleted user']] as [$userId, $who]) {
        $queuedOp = new ReplacementRecord(['fromId' => $c->id, 'toId' => $target->id, 'siteId' => $c->siteId, 'userId' => $userId, 'status' => ReplacementRecord::STATUS_QUEUED]);
        $queuedOp->save(false);
        if ($userId === $ghost->id) {
            $elements->deleteElement($ghost, true);
        }
        (new internetztube\elementRelations\jobs\ReplaceElementJob(['operationId' => $queuedOp->id]))->execute($app->getQueue());
        $queuedOp = ReplacementRecord::findOne($queuedOp->id);
        check($queuedOp->status === ReplacementRecord::STATUS_FAILED && in_array('ER Secret source', relatingTitles($c), true) && in_array('Page via Matrix', relatingTitles($c), true), "a queued replacement of $who is not run (marked failed, nothing changed)");
        check($app->getUser()->getIdentity()?->id === $identityBefore?->id, '…and the job restores the identity it found');
        $queuedOp->delete();
    }

    // --- the history in Utilities as viewer A: elements they may not view are not shown
    $app->getUser()->setIdentity($viewerA);
    $hiddenOp = new ReplacementRecord(['fromId' => $secretEntry->id, 'toId' => $c->id, 'siteId' => $c->siteId, 'userId' => $viewerB->id, 'status' => ReplacementRecord::STATUS_APPLIED, 'changes' => '[]']);
    $hiddenOp->save(false);
    $history = withWebRequest(function () use ($viewerA) {
        Craft::$app->getUser()->setIdentity($viewerA);
        return internetztube\elementRelations\utilities\ElementRelationsUtility::contentHtml();
    });
    check(!str_contains($history, 'data-operation-id="' . $hiddenOp->id . '"') && !str_contains($history, 'ER Secret source'), 'history: viewer A does not see other users\' replacements');
    check(!str_contains($history, 'data-operation-id="' . $adminOpRecord->id . '"'), 'history: not even the admin\'s');
    $ownSecretOp = new ReplacementRecord(['fromId' => $secretEntry->id, 'toId' => $c->id, 'siteId' => $c->siteId, 'userId' => $viewerA->id, 'status' => ReplacementRecord::STATUS_ROLLED_BACK, 'changes' => '[]']);
    $ownSecretOp->save(false);
    $history = withWebRequest(function () use ($viewerA) {
        Craft::$app->getUser()->setIdentity($viewerA);
        return internetztube\elementRelations\utilities\ElementRelationsUtility::contentHtml();
    });
    preg_match('#<tr data-operation-id="' . $ownSecretOp->id . '">(.*?)</tr>#s', $history, $row);
    check($row && !str_contains($row[1], 'ER Secret source') && str_contains($row[1], 'No access'), 'history: an element viewer A may not view (in their own entry) is shown as "No access"');
    $ownSecretOp->delete();
    preg_match('#<tr data-operation-id="' . $mineOp->id . '">(.*?)</tr>#s', $history, $row);
    check($row && str_contains($row[1], 'element-relations/replace/rollback'), 'history: Roll back button on viewer A\'s own replacement');

    // --- front-end templates: only what visitors may see unless asked for
    $b = Entry::find()->title('Page B')->one();
    $siteRequest = function (callable $fn) {
        $request = Craft::$app->getRequest();
        Craft::$app->set('request', ['class' => craft\web\Request::class, 'isCpRequest' => false, 'cookieValidationKey' => Craft::$app->getConfig()->getGeneral()->securityKey, 'url' => '/']);
        try {
            return $fn();
        } finally {
            Craft::$app->set('request', $request);
        }
    };
    $model = new internetztube\elementRelations\models\RelationsModel($b->id, $b->siteId);
    $cpCount = (int)$model->getCount([]);
    [$siteCount, $siteTitles] = $siteRequest(fn() => [(int)$model->getCount([]), array_map('strval', $model->getElements([]))]);
    check($siteCount === $cpCount - 1 && !in_array('Page A12', $siteTitles, true), "front end: the disabled Page A12 is not counted or listed by default ($siteCount vs $cpCount in the control panel)");

    // --- cleanup
    $app->getUser()->setIdentity($identityBefore);
    foreach ([$hiddenOp, $adminOpRecord, $mineOp] as $operation) {
        $operation->delete();
    }
    foreach ([$own, $target, $secretEntry, $viewerA, $viewerB] as $element) {
        $elements->deleteElement($element, true);
    }
    $entriesService->deleteSection($secret);
    $app->edition = $craftEditionBefore;
    $plugins->switchEdition('element-relations', $editionBefore);
    $flush();
};
