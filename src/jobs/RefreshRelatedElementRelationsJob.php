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
    public ?string $description = 'Element Relations: Refresh Related Element Relations';

    /** @var string */
    public const DESCRIPTION_FORMAT = 'Element Relations: Refresh Related Relations %s';

    /** @var mixed */
    public $identifier;

    /** @var string */
    public $dateUpdateRequested;

    public static function createJob($identifier, $dateUpdateRequested = null): void
    {
        if (!CacheService::useCache()) {
            return;
        }
        $description = sprintf(self::DESCRIPTION_FORMAT, $identifier);
        $isAlreadyInQueue = collect(\Craft::$app->queue->getJobInfo())->filter(function (array $job) use ($description) {
            return $job['description'] === $description;
        })->isNotEmpty();

        if (!$dateUpdateRequested) {
            $dateUpdateRequested = date('c');
        }

        if ($isAlreadyInQueue) {
            return;
        }

        $job = new self([
            'identifier' => $identifier,
            'dateUpdateRequested' => $dateUpdateRequested,
            'description' => $description,
        ]);
        \Craft::$app->getQueue()->delay(10)->priority(4096)->push($job);
    }

    public function execute($queue): void
    {
        $relatedElementRelations = CacheService::getRelatedElementRelations($this->identifier);
        $count = count($relatedElementRelations);
        foreach ($relatedElementRelations as $index => $elementId) {
            RefreshElementRelationsJob::createJob($elementId, true, $this->dateUpdateRequested);
            $queue->setProgress(($index + 1) * 100 / $count);
        }
    }
}