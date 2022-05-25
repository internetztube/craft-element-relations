<?php

namespace internetztube\elementRelations\jobs;

use Craft;
use craft\queue\BaseJob;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\services\SeomaticService;

class EventSeomaticGlobalAfterSaveJob extends BaseJob
{
    /** @var string */
    public ?string $description = 'Element Relations: Event SEOmatic Global After Save';

    /** @var string */
    public const DESCRIPTION_FORMAT = 'Element Relations: Event SEOmatic Global After Save';

    public static function createJob(): void
    {
        if (!CacheService::useCache()) {
            return;
        }
        $isAlreadyInQueue = collect(\Craft::$app->queue->getJobInfo())->filter(function (array $job) {
            return $job['description'] === self::DESCRIPTION_FORMAT;
        })->isNotEmpty();
        if ($isAlreadyInQueue) {
            return;
        }
        $job = new self();
        Craft::$app->getQueue()->delay(10)->priority(4096)->push($job);
    }

    public function execute($queue): void
    {
        collect(SeomaticService::getGlobalSeomaticAssets())
            ->pluck('elementId')
            ->unique()
            ->each(function ($elementId) {
                RefreshElementRelationsJob::createJob($elementId);
            });

        RefreshRelatedElementRelationsJob::createJob(SeomaticService::IDENTIFIER_SEOMATIC_GLOBAL);
    }
}