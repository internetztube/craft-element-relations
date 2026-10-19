<?php

declare(strict_types=1);

namespace internetztube\elementRelations\services;

use Craft;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\jobs\ResaveElementRelationsJob;
use yii\base\Component;

/** Collects the elements saved during a request and refreshes their relations in queue jobs of `bulkRefreshBatchSize`. */
class QueueElementsForRefreshService extends Component
{
    /** @var list<array{elementId: int, siteId: int}> */
    private array $items = [];
    private static self $instance;

    public static function getInstance(): self
    {
        if (!isset(self::$instance)) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    public static function registerForRefresh(int $elementId, int $siteId): void
    {
        $instance = self::getInstance();
        $instance->items[] = [
            'elementId' => $elementId,
            'siteId' => $siteId,
        ];

        $batchSize = ElementRelations::$plugin->getSettings()->bulkRefreshBatchSize;

        if (count($instance->items) >= $batchSize) {
            self::pushToQueue();
        }
    }

    public static function destruct(): void
    {
        $instance = self::getInstance();
        if (!empty($instance->items)) {
            self::pushToQueue();
        }
    }

    private static function pushToQueue(): void
    {
        $instance = self::getInstance();
        $job = new ResaveElementRelationsJob([
            'items' => $instance->items,
        ]);
        $instance->items = [];
        Craft::$app->getQueue()->priority(1022)->push($job);
    }
}
