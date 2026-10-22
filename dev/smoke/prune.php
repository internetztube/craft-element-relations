<?php
/**
 * Rows nothing can read (their element, owner or target gone, the owner a revision) are removed: by garbage
 * collection (queued), by `element-relations/prune` and once by a migration; on PostgreSQL large tables are rebuilt.
 */

use craft\db\Query;
use craft\elements\Entry;
use internetztube\elementRelations\models\RelationsModel;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;
use internetztube\elementRelations\services\ExtractorService;
use internetztube\elementRelations\services\PruneService;

return function (array $ctx): void {
    $db = Craft::$app->getDb();
    $b = Entry::find()->title('Page B')->one();
    $live = (new Query())->from('{{%elementrelations_cache}}')->where(['targetElementId' => $b->id])->andWhere(['not', ['sourceElementId' => null]])->one();
    $revision = Entry::find()->revisions(true)->status(null)->one()
        ?? Entry::find()->id(Craft::$app->getRevisions()->createRevision($b, force: true))->revisions(true)->status(null)->one();
    $count = fn(): int => (new RelationsModel((int)$b->id, (int)$b->siteId))->getCountFast([]);
    $usagesBefore = $count();
    $rowsOfB = fn(): int => (int)(new Query())->from('{{%elementrelations_cache}}')->where(['targetElementId' => $b->id])->count();
    $liveRowsOfB = $rowsOfB();

    // junk as the garbage collection and revisions left it: owner gone, target gone, owner a revision
    $insertJunk = function () use ($db, $live, $revision, $b): array {
        $now = craft\helpers\Db::prepareDateForDb(new DateTime());
        $base = array_intersect_key($live, array_flip(['sourceSiteId', 'targetSiteId', 'fieldId', 'customFieldUid', 'type'])) + ['dateCreated' => $now, 'dateUpdated' => $now];
        $rows = [
            ['sourceElementId' => 999999901, 'sourcePrimaryOwnerId' => 999999901, 'targetElementId' => $b->id],
            ['sourceElementId' => $live['sourceElementId'], 'sourcePrimaryOwnerId' => $live['sourcePrimaryOwnerId'], 'targetElementId' => 999999902],
            ['sourceElementId' => $revision->id, 'sourcePrimaryOwnerId' => $revision->id, 'targetElementId' => $b->id],
        ];
        $uids = [];
        foreach ($rows as $row) {
            $uids[] = $uid = craft\helpers\StringHelper::UUID();
            $db->createCommand()->insert('{{%elementrelations_cache}}', $row + $base + ['uid' => $uid])->execute();
        }
        return $uids;
    };
    $junkLeft = fn(array $uids): int => (int)(new Query())->from('{{%elementrelations_cache}}')->where(['uid' => $uids])->count();

    $uids = $insertJunk();
    check($junkLeft($uids) === 3 && $count() === $usagesBefore, 'junk rows (owner gone, target gone, owner a revision) are not counted as usages');
    PruneService::prune(rebuild: false);
    check($junkLeft($uids) === 0, 'prune (batched deletes) removes rows whose owner or target is gone, or whose owner is a revision');
    check($rowsOfB() === $liveRowsOfB && $count() === $usagesBefore, 'prune keeps every live row');

    // garbage collection queues the prune (it may run at the end of any web request)
    $uids = $insertJunk();
    Craft::$app->getGc()->trigger(craft\services\Gc::EVENT_RUN);
    flushQueue();
    check($junkLeft($uids) === 0 && $rowsOfB() === $liveRowsOfB, 'garbage collection removes the rows of the elements it deleted (via a queue job)');

    // large tables: the rows to keep copied out, the table emptied, the rows copied back
    $indexes = function () use ($db): array {
        $db->getSchema()->refreshTableSchema('{{%elementrelations_cache}}');
        $out = [];
        foreach ($db->getSchema()->getTableIndexes('{{%elementrelations_cache}}', true) as $index) {
            $out[(string)$index->name] = [$index->columnNames, (bool)$index->isUnique, (bool)$index->isPrimary];
        }
        ksort($out);
        return $out;
    };
    $uids = $insertJunk();
    $indexesBefore = $indexes();
    $total = (int)(new Query())->from('{{%elementrelations_cache}}')->count();
    $lines = [];
    $removed = PruneService::prune(function (string $line) use (&$lines): void {
        $lines[] = $line;
    }, rebuild: true);
    check($junkLeft($uids) === 0 && $removed === 3 && (int)(new Query())->from('{{%elementrelations_cache}}')->count() === $total - 3 && str_contains(implode(' ', $lines), 'keeping ' . ($total - 3)), 'the rebuild keeps exactly the live rows and says so');
    check($indexes() === $indexesBefore, 'the rebuilt table keeps its indexes, their names and the primary key');
    $record = new ElementRelationsCacheRecord(array_intersect_key($live, array_flip(['sourceElementId', 'sourceSiteId', 'sourcePrimaryOwnerId', 'targetElementId', 'targetSiteId', 'fieldId', 'customFieldUid', 'type'])));
    check($record->save() && $record->id > max(array_map(intval(...), (new Query())->select(['id'])->from('{{%elementrelations_cache}}')->where(['not', ['id' => $record->id]])->column())), 'new rows still get new ids after the rebuild');
    $record->delete();
    check($rowsOfB() === $liveRowsOfB && $count() === $usagesBefore, 'usages are unchanged after the rebuild');

    // revisions (and the nested elements Craft keeps for them) get no rows
    ExtractorService::refreshRelationsForElement($revision);
    check(!(new Query())->from('{{%elementrelations_cache}}')->where(['sourceElementId' => $revision->id])->exists(), 'refreshing a revision writes no rows');
    $ofRevisions = (new Query())
        ->from(['c' => '{{%elementrelations_cache}}'])
        ->innerJoin(['o' => craft\db\Table::ELEMENTS], '[[o.id]] = [[c.sourcePrimaryOwnerId]]')
        ->where(['not', ['o.revisionId' => null]])
        ->count();
    check((int)$ofRevisions === 0, 'no cache row belongs to a revision (nested elements of revisions included)');
};
