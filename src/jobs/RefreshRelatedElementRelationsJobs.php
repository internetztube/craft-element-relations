<?php

namespace internetztube\elementRelations\jobs;

use craft\queue\BaseJob;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\services\ElementRelationsService;

class RefreshRelatedElementRelationsJobs extends BaseJob
{
    public $description = 'Refresh Related Element Relations';
    public $elementId = null;
    public $siteId = null;

    public function execute($queue)
    {
        if (!CacheService::useCache()) {
            return;
        }
        $relatedElementRelations = CacheService::getRelatedElementRelations($this->elementId, $this->siteId);
        $count = count($relatedElementRelations);
        foreach ($relatedElementRelations as $index => $relatedElementRelation) {
            $element = ElementRelationsService::getElementById($relatedElementRelation['elementId'], $relatedElementRelation['siteId']);
            if (!$element) {
                CacheService::deleteElementRelationsRecord($relatedElementRelation['elementId'], $relatedElementRelation['siteId']);
            }
            CacheService::getElementRelationsCached($element, true);
            $queue->setProgress(($index + 1) * 100 / $count);
        }
    }
}