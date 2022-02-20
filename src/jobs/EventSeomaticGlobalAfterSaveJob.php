<?php

namespace internetztube\elementRelations\jobs;

use Craft;
use craft\queue\BaseJob;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\services\SeomaticService;

class EventSeomaticGlobalAfterSaveJob extends BaseJob
{
    /** @var string */
    public $description = 'Element Relations: Event SEOmatic Global After Save';

    public function execute($queue): void
    {
        if (!CacheService::useCache()) {
            return;
        }

        $elementIds = collect(SeomaticService::getGlobalSeomaticAssets())
            ->pluck('elementId')->unique();
        $job = new RefreshElementRelationsJob(['elementIds' => $elementIds, 'force' => true]);
        Craft::$app->getQueue()->delay(10)->priority(10)->push($job);

        $job = new RefreshRelatedElementRelationsJob([
            'identifier' => SeomaticService::IDENTIFIER_SEOMATIC_GLOBAL
        ]);
        Craft::$app->getQueue()->delay(10)->priority(10)->push($job);
    }
}