<?php

namespace internetztube\elementRelations\jobs;

use craft\queue\BaseJob;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\services\ElementRelationsService;

/**
 * This jobs refreshes all element relations when an element in used.
 * Class RefreshRelatedElementRelationsJob
 * @package internetztube\elementRelations\jobs
 */
class RefreshRelatedElementRelationsJob extends BaseJob
{
    /** @var string  */
    public $description = 'Refresh Related Element Relations';

    /** @var int */
    public $elementId;

    /** @var int */
    public $siteId;

    public function execute($queue)
    {
        if (!CacheService::useCache()) { return; }
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