<?php

namespace internetztube\elementRelations\jobs;

use craft\queue\BaseJob;
use internetztube\elementRelations\services\CacheService;

/**
 * This job processes the actual refreshing. See CreateRefreshElementRelationsJobsJob.
 * Class RefreshElementRelationsJob
 * @package internetztube\elementRelations\jobs
 */
class RefreshElementRelationsJob extends BaseJob
{
    public ?string $description = 'Element Relations: Refresh';
    public const DESCRIPTION_FORMAT = 'Element Relations: Refresh %s';
    public bool $force = false;
    public int $elementId;
    public string $dateUpdateRequested;

    /**
     * Create a RefreshElementRelationsJob and push it into the queue.
     * @param $elementId
     * @param bool $force
     * @param null $dateUpdateRequested
     */
    public static function createJob($elementId, bool $force = true, $dateUpdateRequested = null): void
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

        if (!$dateUpdateRequested) {
            $dateUpdateRequested = date('c');
        }

        $job = new self([
            'elementId' => $elementId,
            'force' => $force,
            'description' => $description,
            'dateUpdateRequested' => $dateUpdateRequested,
        ]);
        \Craft::$app->queue->priority(4096)->push($job);
    }

    public function execute($queue): void
    {
        $datePushed = (int)strtotime($this->dateUpdateRequested);
        $dateUpdated = (int)strtotime(CacheService::getDateUpdatedFromElementRelations($this->elementId));

        // Don't refresh element that has been refresh since the creation of this job.
        if ($dateUpdated > $datePushed) {
            return;
        }

        ini_set('memory_limit', -1);
        CacheService::getElementRelationsCached($this->elementId, $this->force);
    }
}