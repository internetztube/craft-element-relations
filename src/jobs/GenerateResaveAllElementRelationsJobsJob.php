<?php

declare(strict_types=1);

namespace internetztube\elementRelations\jobs;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\queue\BaseJob;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\models\RelationsModel;

/**
 * Starts a full rebuild of the relations cache: plugin settings right away, every element in every site in
 * `rebuildParallelJobs` lanes (RebuildRelationsJob), each working through its slice batch by batch.
 */
class GenerateResaveAllElementRelationsJobsJob extends BaseJob
{
    public function execute($queue): void
    {
        RelationsModel::refreshGlobalUsages();
        $lanes = self::lanes(ElementRelations::$plugin->getSettings()->rebuildParallelJobs);
        foreach ($lanes as $i => $lane) {
            Craft::$app->getQueue()->push(new RebuildRelationsJob(['lane' => $i + 1, 'lanes' => count($lanes), 'fromId' => $lane['fromId'], 'toId' => $lane['toId'], 'total' => $lane['total']]));
        }
    }

    /**
     * The elements of query() in at most `$count` slices of about the same size, by element id (an element is never
     * split, so all its sites are in one slice). Together they cover every element exactly once.
     * @return list<array{fromId: int, toId: int, total: int}> element ids above `fromId`, up to and including `toId`
     */
    public static function lanes(int $count): array
    {
        $total = (int)self::query()->count();
        $order = ['elements_sites.elementId' => SORT_ASC, 'elements_sites.siteId' => SORT_ASC];
        // the element at every count-th position; the last one closes the last slice
        $bounds = [0];
        foreach (range(1, max(1, $count)) as $k) {
            $bounds[] = (int)self::query()->select(['elements_sites.elementId'])->orderBy($order)->offset(min($total, intdiv($total * $k, max(1, $count))) - 1)->limit(1)->scalar();
        }
        $bounds = array_values(array_unique($bounds));
        $lanes = [];
        foreach (array_slice($bounds, 1) as $i => $toId) {
            $lanes[] = ['fromId' => $bounds[$i], 'toId' => $toId, 'total' => (int)self::query()->andWhere(['>', 'elements_sites.elementId', $bounds[$i]])->andWhere(['<=', 'elements_sites.elementId', $toId])->count()];
        }
        return array_values(array_filter($lanes, fn(array $lane): bool => $lane['total'] > 0));
    }

    /**
     * Every element in every site a full rebuild goes through. Trashed elements too: they keep their usages (restoring
     * one needs no refresh); ResaveElementRelationsJob leaves out the nested ones that were removed on their own (a
     * Matrix block taken out of its field). Not revisions, nor the Neo blocks a field converted to Matrix left behind
     * (millions on a large site), whose rows prune removes anyway.
     * @return Query<array-key, mixed> rows of elementId and siteId
     */
    public static function query(): Query
    {
        return (new Query())
            ->select([
                'elements_sites.elementId',
                'elements_sites.siteId',
            ])
            ->from(['elements_sites' => Table::ELEMENTS_SITES])
            ->innerJoin(['elements' => Table::ELEMENTS], "[[elements.id]] = [[elements_sites.elementId]]")
            ->where(['is', 'elements.revisionId', null])
            ->andWhere(\internetztube\elementRelations\services\PruneService::notLeftBehindByNeo('elements_sites.elementId'));
    }

    protected function defaultDescription(): ?string
    {
        return "Generate Resave All Element Relations Jobs";
    }
}
