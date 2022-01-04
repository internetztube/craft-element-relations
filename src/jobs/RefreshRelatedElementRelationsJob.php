<?php

namespace internetztube\elementRelations\jobs;

use craft\queue\BaseJob;
use internetztube\elementRelations\services\CacheService;

/**
 * This jobs refreshes all element relations when an element in used.
 * Class RefreshRelatedElementRelationsJob
 * @package internetztube\elementRelations\jobs
 */
class RefreshRelatedElementRelationsJob extends BaseJob
{
    /** @var string */
    public $description = 'Refresh Related Element Relations';

    /** @var int */
    public $identifier;

    public function execute($queue)
    {
        if (!CacheService::useCache()) {
            return;
        }
        $relatedElementRelations = CacheService::getRelatedElementRelations($this->identifier);
        $count = count($relatedElementRelations);
        foreach ($relatedElementRelations as $index => $elementId) {
            CacheService::getElementRelationsCached($elementId, true);
            $queue->setProgress(($index + 1) * 100 / $count);
        }
    }
}