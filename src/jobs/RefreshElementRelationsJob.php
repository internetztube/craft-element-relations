<?php

namespace internetztube\elementRelations\jobs;

use craft\queue\BaseJob;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\services\ElementRelationsService;

class RefreshElementRelationsJob extends BaseJob
{
    public $description = 'Refresh Element Relations Cache';
    public $force = false;
    public $elements = [];

    public function execute($queue)
    {
        $count = count($this->elements);
        foreach ($this->elements as $index => $row) {
            $element = ElementRelationsService::getElementById($row['elementId'], $row['siteId']);
            if (!$element) { continue; }
            CacheService::getRelationsCached($element, $this->force);
            $queue->setProgress($index * 100 / $count);
        }
    }
}