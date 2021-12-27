<?php

namespace internetztube\elementRelations\jobs;

use craft\queue\BaseJob;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\services\ElementRelationsService;

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

    /** @var array */
    public $elements = [];

    public function execute($queue)
    {
        $count = count($this->elements);
        foreach ($this->elements as $index => $row) {
            $element = ElementRelationsService::getElementById($row['elementId'], $row['siteId']);
            if (!$element) {
                continue;
            }
            CacheService::getElementRelationsCached($element, $this->force);
            $queue->setProgress($index * 100 / $count);
        }
    }
}