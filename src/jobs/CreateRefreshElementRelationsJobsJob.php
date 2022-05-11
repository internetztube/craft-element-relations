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

    public bool $force = false;

    public function execute($queue): void
    {
        if (!CacheService::useCache()) {
            return;
        }
        ini_set('memory_limit', -1);
        $rows = collect(ElementRelationsService::getElementsWithElementRelationsField());
        $count = $rows->count();
        $rows = $rows->filter(function (int $elementId, int $index) use ($queue, $count) {
            $queue->setProgress($index * 50 / $count);
            if ($this->force) {
                return true;
            }
            return !CacheService::hasStoredElementRelations($elementId);
        })->values();

        $queue = Craft::$app->getQueue()->delay(10);

        $count = $rows->count();
        $rows->each(function (int $elementId, int $index) use ($queue, $count) {
            RefreshElementRelationsJob::createJob($elementId, $this->force);
            $queue->setProgress(50 + ($index * 50 / $count));
        });
    }
}