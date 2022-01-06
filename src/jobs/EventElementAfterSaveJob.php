<?php

namespace internetztube\elementRelations\jobs;

use Craft;
use craft\queue\BaseJob;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\services\ElementRelationsService;

class EventElementAfterSaveJob extends BaseJob
{
    /** @var string */
    public $description = 'Event Element After Save';

    /** @var int */
    public $elementId;

    public function execute($queue): void
    {
        if (!CacheService::useCache()) {
            return;
        }

        // refresh cache of old relations (where this element is used)
        $job = new RefreshRelatedElementRelationsJob(['identifier' => $this->elementId]);
        Craft::$app->getQueue()->delay(10)->push($job);

        // refresh cache of new relations (elements used in the element)
        $elementsUsedInThisElement = ElementRelationsService::getRelationsUsedInElement($this->elementId);
        if (!empty($elementsUsedInThisElement)) {
            $job = new RefreshElementRelationsJob(['elementIds' => $elementsUsedInThisElement, 'force' => true]);
            Craft::$app->getQueue()->delay(10)->push($job);
        }
    }
}