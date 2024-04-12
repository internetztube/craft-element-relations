<?php

namespace internetztube\elementRelations\jobs;

use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craft\queue\BaseJob;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\services\ElementRelationsService;

/**
 * This job processes the actual refreshing. See CreateRefreshElementRelationsJobsJob.
 * Class RefreshElementRelationsJob
 * @package internetztube\elementRelations\jobs
 */
class RefreshElementRelationsJob extends BaseJob
{
    public ?string $description = 'Element Relations: Refresh';
    public const DESCRIPTION_FORMAT = 'Element Relations: Refresh %s';
    public int $elementId;
    public string $dateUpdateRequested;

    /**
     * Create a RefreshElementRelationsJob and push it into the queue.
     * @param $elementId
     * @param null $dateUpdateRequested
     */
    public static function createJob($elementId, int $priority = 4096, $dateUpdateRequested = null)
    {
        // Increase priority when needed
        if (self::getQueueStatus($elementId) == 'queued') {
            $queuedJob = self::getQueuedJob($elementId);
            if ($queuedJob['priority'] > $priority) {
                Db::update(Table::QUEUE, [
                    'priority' => $priority,
                ], ['id' => $queuedJob['id']], [], false, \Craft::$app->db);
            }
            return null;
        }

        if (self::getQueueStatus($elementId) == 'error') {
            return null;
        }

        if (!$dateUpdateRequested) {
            $dateUpdateRequested = date('c');
        }

        $job = new self([
            'elementId' => $elementId,
            'description' => sprintf(self::DESCRIPTION_FORMAT, $elementId),
            'dateUpdateRequested' => $dateUpdateRequested,
        ]);
        \Craft::$app->queue->priority($priority)->push($job);
    }

    public static function getQueueStatus($elementId): string
    {
        $result = self::getQueuedJob($elementId);
        if (!$result) {
            return 'not-found';
        }
        if (!is_null($result['error'])) {
            return 'error';
        }
        return 'queued';
    }

    private static function getQueuedJob($elementId)
    {
        $description = sprintf(self::DESCRIPTION_FORMAT, $elementId);
        return (new Query())->select('*')
            ->from(Table::QUEUE)
            ->where(['description' => $description])
            ->collect()
            ->first();
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
        $relations = ElementRelationsService::getElementRelations($this->elementId);
        CacheService::setStoredRelations($this->elementId, $relations);
    }
}