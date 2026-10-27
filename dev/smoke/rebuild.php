<?php
/**
 * The full rebuild in lanes (GenerateResaveAllElementRelationsJobsJob → RebuildRelationsJob): the slices cover every
 * element exactly once, a rebuild queues one job per lane, each lane queues itself again until its slice is done, the
 * rebuilt cache is the same as before, and releasing a lane stops it.
 */

use craft\db\Query;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\jobs\GenerateResaveAllElementRelationsJobsJob as Rebuild;

return function (array $ctx): void {
    $settings = ElementRelations::$plugin->getSettings();
    [$parallel, $batch] = [$settings->rebuildParallelJobs, $settings->bulkRefreshBatchSize];
    $db = Craft::$app->getDb();
    $columns = ['sourceElementId', 'sourceSiteId', 'sourcePrimaryOwnerId', 'targetElementId', 'targetSiteId', 'fieldId', 'type', 'globalKey'];
    $rows = function () use ($columns): array {
        $rows = array_map(fn(array $row): string => implode('|', array_map(fn(mixed $v): string => (string)$v, $row)), (new Query())->select($columns)->from('{{%elementrelations_cache}}')->all());
        sort($rows); // PostgreSQL returns rows in any order
        return $rows;
    };
    $key = fn(array $row): string => (int)$row['elementId'] . ':' . (int)$row['siteId'];
    $all = array_map($key, Rebuild::query()->all());
    sort($all);

    // the slices
    $lanes = Rebuild::lanes(3);
    $covered = [];
    foreach ($lanes as $lane) {
        foreach (Rebuild::query()->andWhere(['>', 'elements_sites.elementId', $lane['fromId']])->andWhere(['<=', 'elements_sites.elementId', $lane['toId']])->all() as $row) {
            $covered[] = $key($row);
        }
    }
    sort($covered);
    check(count($lanes) === 3 && $covered === $all && array_sum(array_column($lanes, 'total')) === count($all), 'three slices cover every element in every site exactly once (' . implode(', ', array_column($lanes, 'total')) . ' of ' . count($all) . ')');
    $one = Rebuild::lanes(1);
    $many = Rebuild::lanes(count($all) + 5);
    check(count($one) === 1 && $one[0]['total'] === count($all) && count($many) <= count($all) && min(array_column($many, 'total')) > 0, 'one lane covers everything; more lanes than elements give fewer lanes, none empty');

    $settings->rebuildParallelJobs = 3;
    $settings->bulkRefreshBatchSize = 25; // many batches per lane: every lane queues itself again again and again
    $queued = fn(int $after): array => array_map('intval', (new Query())->select(['id'])->from('{{%queue}}')->where(['>', 'id', $after])->column());
    $start = function () use ($db, $queued): array {
        $db->createCommand()->delete('{{%elementrelations_cache}}', ['not', ['sourceElementId' => null]])->execute();
        $last = (int)(new Query())->from('{{%queue}}')->max('id');
        (new Rebuild())->execute(Craft::$app->getQueue());
        return $queued($last);
    };
    try {
        $before = $rows();
        $jobs = $start();
        check(count($jobs) === 3 && count($all) > 3 * 25, 'a rebuild queues one job per lane, not one per batch (' . count($jobs) . ' jobs for ' . count($all) . ' elements in batches of 25)');
        flushQueue();
        check($rows() === $before, 'the lanes, each queuing itself again batch after batch, rebuild exactly the rows the cache had');

        // releasing a lane (the queue screen's "Release") stops it; the other lanes finish
        $jobs = $start();
        Craft::$app->getQueue()->release((string)$jobs[0]);
        flushQueue();
        $partial = $rows();
        check($partial !== $before && array_diff($partial, $before) === [], 'a released lane stops: its slice stays empty, the other lanes finish');
    } finally {
        // the cache as it was, the settings as they were
        $settings->rebuildParallelJobs = $parallel;
        $settings->bulkRefreshBatchSize = $batch;
        $start();
        flushQueue();
    }
    check($rows() === $before, 'rebuilt again: the cache is as it was');
};
