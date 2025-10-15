<?php

namespace internetztube\elementRelations\services;

use Craft;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\jobs\ResaveElementRelationsJob;
use yii\base\Component;

class QueueElementsForRefreshService extends Component
{
    private array $items = [];
    private static self $instance;

    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    public static function registerForRefresh(int $elementId, int $siteId)
    {
        $instance = self::getInstance();
        $instance->items[] = [
            'elementId' => $elementId,
            'siteId' => $siteId,
        ];

        $batchSize = ElementRelations::getInstance()->getSettings()->bulkRefreshBatchSize;

        if (count($instance->items) >= $batchSize) {
            self::pushToQueue();
        }
    }

    public static function destruct()
    {
        $instance = self::getInstance();
        if (!empty($instance->items)) {
            self::pushToQueue();
        }
    }

    private static function pushToQueue()
    {
        $instance = self::getInstance();
        $job = new ResaveElementRelationsJob([
            'items' => $instance->items,
        ]);
        $instance->items = [];
        Craft::$app->getQueue()->priority(1022)->push($job);
    }
}
