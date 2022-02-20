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
    /** @var string */
    public $description = 'Element Relations: Refresh Cache';

    /** @var bool */
    public $force = false;

    /** @var int[] */
    public $elementIds = [];

    public function execute($queue): void
    {
        if (!CacheService::useCache()) {
            return;
        }
        $count = count($this->elementIds);
        foreach ($this->elementIds as $index => $elementId) {
            CacheService::getElementRelationsCached($elementId, $this->force);
            $queue->setProgress($index * 100 / $count);
        }
    }
}