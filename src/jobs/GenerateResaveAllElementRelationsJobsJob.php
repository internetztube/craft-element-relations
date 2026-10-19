<?php

declare(strict_types=1);

namespace internetztube\elementRelations\jobs;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craft\queue\BaseJob;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\models\RelationsModel;

/**
 * Splits a full rebuild of the relations cache into ResaveElementRelationsJob batches of every element in every site.
 */
class GenerateResaveAllElementRelationsJobsJob extends BaseJob
{
    public function execute($queue): void
    {
        RelationsModel::refreshGlobalUsages();

        $query = (new Query())
            ->select([
                'elements_sites.elementId',
                'elements_sites.siteId',
            ])
            ->from(['elements_sites' => Table::ELEMENTS_SITES])
            ->innerJoin(['elements' => Table::ELEMENTS], "[[elements.id]] = [[elements_sites.elementId]]")
            ->where(['is', 'elements.dateDeleted', null])
            ->andWhere(['is', 'elements.revisionId', null]);

        $batchSize = ElementRelations::$plugin->getSettings()->bulkRefreshBatchSize;

        $totalCount = (int)$query->count();
        $totalBatches = ceil($totalCount / $batchSize);
        $index = 0;

        /** @var list<array{elementId: int|string, siteId: int|string}> $items */
        foreach (Db::batch($query, $batchSize) as $items) {
            $index += 1;
            $job = new ResaveElementRelationsJob([
                'items' => $items,
                'description' => "Resave Element Relations ($index/$totalBatches)"
            ]);
            Craft::$app->getQueue()->push($job);
            $this->setProgress($queue, $index / $totalBatches, "$index/$totalBatches");
        }
    }

    protected function defaultDescription(): ?string
    {
        return "Generate Resave All Element Relations Jobs";
    }
}
