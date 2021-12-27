<?php

namespace internetztube\elementRelations\jobs;

use Craft;
use craft\queue\BaseJob;
use internetztube\elementRelations\services\ElementRelationsService;
use Tightenco\Collect\Support\Collection;

class CreateRefreshElementRelationsJobsJob extends BaseJob
{
    public $description = 'Create Refresh Element Relations Cache Jobs';
    public $force = false;

    public function execute($queue)
    {
        $rows = ElementRelationsService::getElementsWithElementRelationsField();
        $queue = Craft::$app->getQueue();

        $jobSize = 1000;
        $chunks = collect($rows)->chunk($jobSize);
        $count = $chunks->count();

        $chunks->each(function(Collection $chunk, $index) use ($queue, $count) {
            $job = new RefreshElementRelationsJob([
                'description' => sprintf('Refresh Element Relations Cache %d/%d', $index+1, $count),
                'force' => $this->force,
                'elements' => $chunk->values()->toArray()
            ]);
            $queue->push($job);
            $queue->setProgress(($index+1) * 100 / $count);
        });
    }
}