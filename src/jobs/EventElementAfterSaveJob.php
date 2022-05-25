<?php

namespace internetztube\elementRelations\jobs;

use Craft;
use craft\queue\BaseJob;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\services\ElementRelationsService;

class EventElementAfterSaveJob extends BaseJob
{
    /** @var string */
    public ?string $description = 'Element Relations: Event Element After Save';

    /** @var string */
    public const DESCRIPTION_FORMAT = 'Element Relations: Event Element After Save %s';

    /** @var int */
    public $elementId;

    /** @var string */
    public $dateUpdateRequested;

    public static function createJob($elementId): void
    {
        if (!CacheService::useCache()) {
            return;
        }
        $description = sprintf(self::DESCRIPTION_FORMAT, $elementId);
        $isAlreadyInQueue = collect(\Craft::$app->queue->getJobInfo())->filter(function (array $job) use ($description) {
            return $job['description'] === $description;
        })->isNotEmpty();
        if ($isAlreadyInQueue) {
            return;
        }

        $job = new self([
            'elementId' => $elementId,
            'dateUpdateRequested' => date('c'),
            'description' => $description,
        ]);
        Craft::$app->getQueue()->delay(10)->priority(4096)->push($job);
    }

    public function execute($queue): void
    {
        // refresh cache of old relations (where this element is used)
        RefreshRelatedElementRelationsJob::createJob($this->elementId, $this->dateUpdateRequested);

        // refresh cache of new relations (elements used in the element)
        $elementsUsedInThisElement = ElementRelationsService::getRelationsUsedInElement($this->elementId);
        foreach ($elementsUsedInThisElement as $elementId) {
            RefreshElementRelationsJob::createJob($elementId, true, $this->dateUpdateRequested);
        }
    }
}