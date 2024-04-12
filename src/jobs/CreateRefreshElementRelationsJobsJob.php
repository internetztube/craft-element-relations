<?php

namespace internetztube\elementRelations\jobs;

use Craft;
use craft\queue\BaseJob;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\services\ElementRelationsService;

/**
 * Since the refresh jobs are very time and computation intensive, the jobs are separated into 10 items chunks.
 * Class CreateRefreshElementRelationsJobsJob
 * @package internetztube\elementRelations\jobs
 */
class CreateRefreshElementRelationsJobsJob extends BaseJob
{
    public ?string $description = 'Element Relations: Create Refresh Cache Jobs';

    public function execute($queue): void
    {
        ini_set('memory_limit', -1);
        $rows = collect(ElementRelationsService::getElementsWithElementRelationsField());
        $count = $rows->count();
        $rows->each(function (int $elementId, int $index) use ($queue, $count) {
            RefreshElementRelationsJob::createJob($elementId);
            $queue->setProgress($index * 100 / $count);
        });
    }
}