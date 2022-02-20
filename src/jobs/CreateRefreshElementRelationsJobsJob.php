<?php

namespace internetztube\elementRelations\jobs;

use Craft;
use craft\queue\BaseJob;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\services\ElementRelationsService;
use Illuminate\Support\Collection;

/**
 * Since the refresh jobs are very time and computation intensive, the jobs are separated into 1000 items chunks.
 * Class CreateRefreshElementRelationsJobsJob
 * @package internetztube\elementRelations\jobs
 */
class CreateRefreshElementRelationsJobsJob extends BaseJob
{
    /** @var string */
    public $description = 'Element Relations: Create Refresh Cache Jobs';

    /** @var bool */
    public $force = false;

    public function execute($queue): void
    {
        if (!CacheService::useCache()) {
            return;
        }
        $rows = ElementRelationsService::getElementsWithElementRelationsField();
        $queue = Craft::$app->getQueue()->delay(10);

        $jobSize = 100;
        $chunks = $rows->chunk($jobSize);
        $count = $chunks->count();

        $chunks->each(function (Collection $chunk, $index) use ($queue, $count) {
            $job = new RefreshElementRelationsJob([
                'elementIds' => $chunk->values()->toArray(),
                'force' => $this->force,
                'description' => sprintf('Element Relations: Refresh Cache %d/%d', $index + 1, $count),
            ]);
            $queue->push($job);
            $queue->setProgress(($index + 1) * 100 / $count);
        });
    }
}