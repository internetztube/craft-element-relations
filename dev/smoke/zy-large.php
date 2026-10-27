<?php
/**
 * A large site: one entry used by 70,000 entries, on 12 sites. Every path that takes the usages as a list runs once:
 * PostgreSQL binds at most 65,535 parameters per query, so a list bound one id per parameter breaks here and nowhere
 * in the small fixtures. The 10 extra sites and the 70,000 entries are written straight into the database (Craft's own
 * API would propagate every asset and category into each new site and take minutes) and removed at the end.
 */

declare(strict_types=1);

use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Db;
use internetztube\elementRelations\controllers\ReplaceController;
use internetztube\elementRelations\elements\db\UsageQuery;
use internetztube\elementRelations\elements\Usage;
use internetztube\elementRelations\fields\ElementRelationsField;
use internetztube\elementRelations\models\RelationsModel;
use internetztube\elementRelations\services\InUseGuard;
use internetztube\elementRelations\services\ReplaceService;

return function (array $ctx): void {
    $app = Craft::$app;
    $db = $app->getDb();
    $sites = $app->getSites();
    $owners = 70000; // above PostgreSQL's 65,535 parameters
    $perExtraSite = 1000; // the first owners also use it in each of the other 11 sites
    $uidPrefix = 'e1a7e000-0000-4000-8000-'; // marks the rows this file writes, for the cleanup
    $uid = fn(int $i): string => $uidPrefix . str_pad(dechex($i), 12, '0', STR_PAD_LEFT);
    $now = Db::prepareDateForDb(new DateTime());
    $pages = $app->getEntries()->getSectionByHandle('pages');
    $pageType = $pages->getEntryTypes()[0];
    $fieldId = (int)$app->getFields()->getFieldByHandle('relatedEntries')->id;
    $primary = $sites->getPrimarySite();

    $cleanup = function () use ($db, $sites, $uidPrefix): void {
        $ids = (new Query())->select(['id'])->from(Table::ELEMENTS)->where(['like', 'uid', $uidPrefix . '%', false])->column();
        foreach (array_chunk(array_map(intval(...), $ids), 10000) as $chunk) {
            $db->createCommand()->delete('{{%elementrelations_cache}}', ['or', \internetztube\elementRelations\ElementRelations::idIn('sourcePrimaryOwnerId', $chunk), \internetztube\elementRelations\ElementRelations::idIn('targetElementId', $chunk)])->execute();
            $db->createCommand()->delete(Table::ELEMENTS, \internetztube\elementRelations\ElementRelations::idIn('id', $chunk))->execute(); // entries and elements_sites go with them
        }
        $db->createCommand()->delete(Table::SITES, ['like', 'uid', $uidPrefix . '%', false])->execute();
        $sites->refreshSites();
        foreach (User::find()->username('er-large-viewer')->status(null)->all() as $user) {
            Craft::$app->getElements()->deleteElement($user, true);
        }
        foreach (Entry::find()->title(['ER Large target', 'ER Large replacement'])->status(null)->all() as $entry) {
            Craft::$app->getElements()->deleteElement($entry, true);
        }
    };
    $cleanup(); // leftovers of an aborted run
    // and on every way out: a failed check() exits, which skips `finally`, and 70,000 leftover entries would break
    // the next run's earlier checks
    register_shutdown_function($cleanup);

    // --- the sites: the two of the fixtures and 10 more (in the primary site's group, no URLs)
    $extraSites = [];
    for ($i = 1; $i <= 10; $i++) {
        $extraSites[] = ['groupId' => $primary->groupId, 'primary' => false, 'enabled' => true, 'name' => "Large $i", 'handle' => "erLarge$i", 'language' => 'en-US', 'hasUrls' => false, 'sortOrder' => 100 + $i, 'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => $uid(900000 + $i)];
    }
    $db->createCommand()->batchInsert(Table::SITES, array_keys($extraSites[0]), array_map('array_values', $extraSites))->execute();
    $sites->refreshSites();
    $allSiteIds = array_map(fn($site) => (int)$site->id, $sites->getAllSites(true));
    $otherSiteIds = array_values(array_diff($allSiteIds, [(int)$primary->id]));
    check(count($allSiteIds) >= 12, 'large site: ' . count($allSiteIds) . ' sites');

    try {
        // --- the target and a replacement (through Craft), present in every site
        $make = function (string $title) use ($pages, $pageType, $otherSiteIds, $db, $now): Entry {
            $entry = new Entry(['sectionId' => $pages->id, 'typeId' => $pageType->id, 'title' => $title]);
            Craft::$app->getElements()->saveElement($entry) or throw new RuntimeException(json_encode($entry->getErrors()));
            $present = (new Query())->select(['siteId'])->from(Table::ELEMENTS_SITES)->where(['elementId' => $entry->id])->column();
            $missing = array_values(array_diff($otherSiteIds, array_map(intval(...), $present)));
            $db->createCommand()->batchInsert(Table::ELEMENTS_SITES, ['elementId', 'siteId', 'title', 'enabled', 'dateCreated', 'dateUpdated', 'uid'],
                array_map(fn(int $siteId) => [$entry->id, $siteId, $title, true, $now, $now, craft\helpers\StringHelper::UUID()], $missing))->execute();
            return $entry;
        };
        $target = $make('ER Large target');
        $replacement = $make('ER Large replacement');

        // --- 70,000 entries using it (pages, live), each in the primary site, the first 1,000 in every other site too
        $started = $fileStarted = microtime(true);
        foreach (array_chunk(range(1, $owners), 10000) as $chunk) {
            $db->createCommand()->batchInsert(Table::ELEMENTS, ['type', 'enabled', 'archived', 'dateCreated', 'dateUpdated', 'uid'],
                array_map(fn(int $i) => [Entry::class, true, false, $now, $now, $uid($i)], $chunk))->execute();
        }
        $ownerIds = array_map(intval(...), (new Query())->select(['id'])->from(Table::ELEMENTS)->where(['like', 'uid', $uidPrefix . '%', false])->orderBy(['id' => SORT_ASC])->column());
        check(count($ownerIds) === $owners, 'large site: 70,000 entries written');
        foreach (array_chunk($ownerIds, 10000) as $chunk) {
            $db->createCommand()->batchInsert(Table::ENTRIES, ['id', 'sectionId', 'typeId', 'postDate', 'dateCreated', 'dateUpdated'],
                array_map(fn(int $id) => [$id, $pages->id, $pageType->id, $now, $now, $now], $chunk))->execute();
            $placed = [];
            $rows = [];
            foreach ($chunk as $id) {
                $placed[] = [$id, $primary->id, "Large usage $id", true, $now, $now, craft\helpers\StringHelper::UUID()];
                $rows[] = [$id, $primary->id, $id, $target->id, $primary->id, $fieldId, 'field', $now, $now, craft\helpers\StringHelper::UUID()];
            }
            $db->createCommand()->batchInsert(Table::ELEMENTS_SITES, ['elementId', 'siteId', 'title', 'enabled', 'dateCreated', 'dateUpdated', 'uid'], $placed)->execute();
            $db->createCommand()->batchInsert('{{%elementrelations_cache}}', ['sourceElementId', 'sourceSiteId', 'sourcePrimaryOwnerId', 'targetElementId', 'targetSiteId', 'fieldId', 'type', 'dateCreated', 'dateUpdated', 'uid'], $rows)->execute();
        }
        $placed = [];
        $rows = [];
        foreach ($otherSiteIds as $siteId) {
            foreach (array_slice($ownerIds, 0, $perExtraSite) as $id) {
                $placed[] = [$id, $siteId, "Large usage $id", true, $now, $now, craft\helpers\StringHelper::UUID()];
                $rows[] = [$id, $siteId, $id, $target->id, $siteId, $fieldId, 'field', $now, $now, craft\helpers\StringHelper::UUID()];
            }
        }
        $db->createCommand()->batchInsert(Table::ELEMENTS_SITES, ['elementId', 'siteId', 'title', 'enabled', 'dateCreated', 'dateUpdated', 'uid'], $placed)->execute();
        $db->createCommand()->batchInsert('{{%elementrelations_cache}}', ['sourceElementId', 'sourceSiteId', 'sourcePrimaryOwnerId', 'targetElementId', 'targetSiteId', 'fieldId', 'type', 'dateCreated', 'dateUpdated', 'uid'], $rows)->execute();
        echo sprintf("  (fixtures written in %.1f s)\n", microtime(true) - $started);

        /** Runs one path; its error (not a crash of the whole file) is what the check reports. */
        $try = function (callable $fn): array {
            try {
                return [$fn(), null];
            } catch (Throwable $e) {
                return [null, get_class($e) . ': ' . substr($e->getMessage(), 0, 160)];
            }
        };
        $expectOthers = count($otherSiteIds);

        // --- counts and lists (RelationsModel: Twig, the field, the index column)
        $model = new RelationsModel((int)$target->id, (int)$primary->id);
        [$value, $error] = $try(fn() => [$model->getCountFast([]), $model->getCount([]), array_sum($model->getCountsByType()), count($model->getElements([], 5))]);
        check($value === [$owners, $owners, $owners, 5], 'large site: counts and the first elements (' . json_encode($value) . ') ' . $error);
        [$value, $error] = $try(fn() => count($model->getSites()));
        check($value === count($allSiteIds), "large site: the sites it is used in ($value) $error");
        $here = $model->forTargetSite();
        [$value, $error] = $try(fn() => [$here->getCountFast([]), count($here->getOtherSiteCounts())]);
        check($value === [$owners, $expectOthers], 'large site: the field in the primary site: 70,000 here, the other ' . $expectOthers . ' sites below (' . json_encode($value) . ') ' . $error);
        [$value, $error] = $try(function () use ($target, $primary) {
            RelationsModel::prefetch([(int)$target->id], 5, (int)$primary->id);
            return true;
        });
        check($value === true, "large site: the index column's prefetch $error");

        // --- the field's list (Usage) and Craft's own queries
        [$value, $error] = $try(fn() => [
            (int)Usage::find()->usesElement($target)->allSites()->status(null)->count(),
            count(Usage::find()->usesElement($target)->allSites()->status(null)->limit(20)->all()),
            (int)Usage::find()->usesElement($target)->usesElementSite($otherSiteIds[0])->allSites()->status(null)->count(),
            count(Craft::configure(Usage::find()->usesElement($target)->allSites()->status(null), ['inSites' => array_slice($otherSiteIds, 0, 3), 'preferredSiteId' => $otherSiteIds[1]])->limit(20)->all()),
        ]);
        check($value === [$owners, 20, $perExtraSite, 20], 'large site: the field\'s list: count, first page, one site, some sites (' . json_encode($value) . ') ' . $error);
        [$value, $error] = $try(fn() => [(int)Entry::find()->usesElement($target)->status(null)->count(), (int)Entry::find()->section('pages')->unused(false)->id([$target->id, $ownerIds[0]])->status(null)->count()]);
        check($value === [$owners, 1], 'large site: usesElement, and unused(false) on entries: the target is used, a usage itself is not (' . json_encode($value) . ') ' . $error);
        [$value, $error] = $try(fn() => count(Entry::find()->section('pages')->status(null)->orderBy([Craft::$app->getFields()->getFieldByHandle('relations')->getSortOption()['orderBy'] => SORT_DESC])->limit(3)->ids()));
        check($value === 3, "large site: sorting entries by the field $error");

        // --- an editor with limited permissions (the view check takes every usage)
        $viewer = new User(['username' => 'er-large-viewer', 'email' => 'er-large-viewer@example.com', 'admin' => false]);
        Craft::$app->getElements()->saveElement($viewer) or throw new RuntimeException(json_encode($viewer->getErrors()));
        Craft::$app->getUserPermissions()->saveUserPermissions($viewer->id, ['accesscp', "viewentries:$pages->uid", "viewpeerentries:$pages->uid", ...array_map(fn($site) => "editsite:$site->uid", $sites->getAllSites(true))]);
        [$value, $error] = $try(fn() => [(int)Usage::find()->usesElement($target)->allSites()->status(null)->viewableBy($viewer)->count(), UsageQuery::hiddenCount([(int)$target->id], $viewer)]);
        check(is_array($value) && $value[0] > 0 && $value[0] + $value[1] === $owners, 'large site: what an editor may view, and what not (' . json_encode($value) . ') ' . $error);

        // --- the field, its index column and the export, as an admin and as the editor
        $field = Craft::$app->getFields()->getFieldByHandle('relations');
        [$value, $error] = $try(fn() => withWebRequest(fn() => [strlen($field->getInputHtml(new RelationsModel((int)$target->id, (int)$primary->id), $target)) > 0, strlen($field->getPreviewHtml(new RelationsModel((int)$target->id, (int)$primary->id), $target)) > 0, $field->serializeValue(new RelationsModel((int)$target->id, (int)$primary->id), $target)['count'] ?? null]));
        check($value === [true, true, $owners], 'large site: the field, the index column and the export (' . json_encode($value) . ') ' . $error);
        [$value, $error] = $try(fn() => withWebRequest(function () use ($field, $target, $primary, $viewer) {
            Craft::$app->getUser()->setIdentity($viewer);
            return [strlen($field->getInputHtml(new RelationsModel((int)$target->id, (int)$primary->id), $target)) > 0, strlen($field->getPreviewHtml(new RelationsModel((int)$target->id, (int)$primary->id), $target)) > 0, $field->serializeValue(new RelationsModel((int)$target->id, (int)$primary->id), $target)['count'] ?? null];
        }));
        check(is_array($value) && $value[0] && $value[1] && $value[2] > 0, 'large site: the field, the index column and the export for the editor (' . json_encode($value) . ') ' . $error);

        // --- the delete protection
        [$value, $error] = $try(fn() => InUseGuard::liveUsages($target)['count']);
        check($value === $owners, "large site: the delete protection counts every live usage ($value) $error");

        // --- GraphQL: Craft's own queries refuse usesElement above the limit, with a message (no SQL error)
        $result = Craft::$app->getGql()->executeQuery(new craft\models\GqlSchema(['uid' => craft\helpers\StringHelper::UUID(), 'scope' => ["sections.$pages->uid:read", "entrytypes.$pageType->uid:read", ...array_map(fn($site) => "sites.$site->uid:read", $sites->getAllSites(true))]]), '{ entries(usesElement: ' . $target->id . ', limit: 1) { id } }');
        check(str_contains(json_encode($result['errors'] ?? []), 'more than'), 'large site: GraphQL usesElement says it is too many: ' . json_encode($result['errors'][0]['message'] ?? $result));

        // --- replacing: the wizard for all in the list, then queuing it for the list and everywhere
        $controller = new ReplaceController('replace', \internetztube\elementRelations\ElementRelations::getInstance());
        $private = fn(string $method, array $args) => (new ReflectionMethod($controller, $method))->invokeArgs($controller, $args);
        [$value, $error] = $try(fn() => [count($private('listRows', [$target, []])), count($private('wizardSites', [$target, $ownerIds])), strlen(ElementRelationsField::revisionHintFor($target, $ownerIds)) >= 0]);
        check(is_array($value) && $value[0] === $owners && $value[1] === count($allSiteIds) && $value[2], 'large site: the wizard for all 70,000 in the list: rows, sites, revision hint (' . json_encode($value) . ') ' . $error);
        $lastJob = (int)(new Query())->from('{{%queue}}')->max('id');
        $oneSite = (int)array_values($allSiteIds)[0];
        [$value, $error] = $try(fn() => [ReplaceService::replace($target, $replacement, $ownerIds, queue: true)['operationId'] !== null, ReplaceService::replace($target, $replacement, null, queue: true)['operationId'] !== null, ReplaceService::replace($target, $replacement, $ownerIds, queue: true, onlySiteId: $oneSite)['operationId'] !== null]);
        check($value === [true, true, true], 'large site: replacing in all 70,000, everywhere, and in one site only is planned and queued (' . json_encode($value) . ') ' . $error);
        foreach ((new Query())->select(['id'])->from('{{%queue}}')->where(['>', 'id', $lastJob])->column() as $jobId) {
            Craft::$app->getQueue()->release((string)$jobId); // planned only: running 70,000 saves is not the point here
        }
        [$value, $error] = $try(fn() => [ReplaceService::elementCount((int)$target->id, $ownerIds), count(ReplaceService::types($ownerIds))]);
        check($value === [$owners, $owners], 'large site: counting what a replacement changed, and the types of 70,000 elements (' . json_encode($value) . ') ' . $error);
        [$value, $error] = $try(fn() => [ReplaceService::elementCount((int)$target->id, $ownerIds, [$oneSite]) <= $owners, is_array(ReplaceService::sharedWithOtherSites($target, $oneSite, $ownerIds)), is_array(ReplaceService::sharedWithOtherSites($target, $oneSite))]);
        check($value === [true, true, true], 'large site: the count in one site, and which of 70,000 usages in one site are shared with other sites, for the list and everywhere (' . json_encode($value) . ') ' . $error);
        echo sprintf("  (every path ran in %.1f s)\n", microtime(true) - $fileStarted);
    } finally {
        $cleanup();
    }
    check(!(new Query())->from(Table::ELEMENTS)->where(['like', 'uid', $uidPrefix . '%', false])->exists() && count($sites->getAllSites(true)) === count($allSiteIds) - 10, 'large site: everything it wrote is gone again');
};
