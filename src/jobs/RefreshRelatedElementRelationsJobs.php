<?php

namespace internetztube\elementRelations\jobs;

use craft\queue\BaseJob;
use internetztube\elementRelations\services\CacheService;

class RefreshRelatedElementRelationsJobs extends BaseJob
{
    public $description = 'Refresh Related Element Relations';
    public $elementId = null;
    public $siteId = null;

    public function execute($queue)
    {
        if (!CacheService::getUseCache()) { return; }
        $relationsRecords = CacheService::getRelatedRelationsRecords($this->elementId, $this->siteId);
        $count = count($relationsRecords);
        foreach ($relationsRecords as $index => $record) {
            $element = \Craft::$app->elements->getElementById($record->elementId, null, $record->siteId);
            if (!$element) { CacheService::deleteRelationsRecord($record); }
            CacheService::getRelationsCached($element, true);
            $queue->setProgress(($index+1) * 100 / $count);
        }
    }
}