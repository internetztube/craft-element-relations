<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Jobs;

use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Queue\Job;
use Illuminate\Support\Facades\DB;
use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Models\RelationsModel;
use Override;

/** Splits a full rebuild of the relations cache into ResaveElementRelationsJob batches of every element in every site. */
class GenerateResaveAllElementRelationsJobsJob extends Job
{
    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): void
    {
        RelationsModel::refreshGlobalUsages();

        // trashed elements too: they keep their usages (restoring one needs no refresh); ResaveElementRelationsJob
        // leaves out the nested ones that were removed on their own (a Matrix block taken out of its field)
        $query = DB::table(Table::ELEMENTS_SITES)
            ->select(['elements_sites.id', 'elements_sites.elementId', 'elements_sites.siteId'])
            ->join(Table::ELEMENTS, 'elements.id', '=', 'elements_sites.elementId')
            ->whereNull('elements.revisionId');

        $batchSize = ElementRelations::instance()?->getSettings()->bulkRefreshBatchSize ?? 2000;
        $totalBatches = (int)ceil($query->count() / $batchSize);
        $index = 0;
        $query->chunkById($batchSize, function ($rows) use (&$index, $totalBatches): void {
            $index++;
            $items = array_values($rows->map(fn(object $row): array => ['elementId' => (int)$row->elementId, 'siteId' => (int)$row->siteId])->all());
            dispatch(new ResaveElementRelationsJob($items, "Resave Element Relations ($index/$totalBatches)"));
            $this->setProgress(max(0, min(100, intdiv($index * 100, max(1, $totalBatches)))), "$index/$totalBatches");
        }, 'elements_sites.id', 'id');
    }

    #[Override]
    protected function defaultDescription(): string
    {
        return 'Generate Resave All Element Relations Jobs';
    }
}
