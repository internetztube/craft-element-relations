<?php

namespace internetztube\elementRelations\jobs;

use craft\queue\BaseJob;
use internetztube\elementRelations\services\CacheService;

/**
 * This job processes the actual refreshing. See CreateRefreshElementRelationsJobsJob.
 * Class RefreshElementRelationsJob
 * @package internetztube\elementRelations\jobs
 */
class RefreshElementRelationsJob extends BaseJob
{
    public $description = 'Refresh Element Relations Cache';

    /** @var bool */
    public $force = false;

    /** @var int[] */
    public $elements = [];

    public function execute($queue)
    {
        if (!CacheService::useCache()) {
            return;
        }
        $count = count($this->elements);
        foreach ($this->elements as $index => $elementId) {
            CacheService::getElementRelationsCached($elementId, $this->force);
            $queue->setProgress($index * 100 / $count);
        }
    }
}