<?php

namespace internetztube\elementRelations\jobs;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craft\queue\BaseJob;

/**
 * Resave Element Relations Job queue job
 */
class GenerateResaveAllElementRelationsJobsJob extends BaseJob
{
    function execute($queue): void
    {
        $query = (new Query())
            ->select([
                'elements_sites.elementId',
                'elements_sites.siteId',
                'elements.type'
            ])
            ->from(['elements_sites' => Table::ELEMENTS_SITES])
            ->innerJoin(['elements' => Table::ELEMENTS], "[[elements.id]] = [[elements_sites.elementId]]")
            ->where(['is', 'elements.dateDeleted', null])
            ->where(['is', 'elements.revisionId', null]);

        $batchSize = 10000;
        $totalCount = $query->count();
        $totalBatches = ceil($totalCount / $batchSize);
        $index = 0;

        foreach (Db::batch($query, $batchSize) as $batch) {
            $index += 1;
            $job = new ResaveAllElementRelationsJob([
                'batch' => $batch,
                'description' => "Resave All Element Relations ($index/$totalBatches)"
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
