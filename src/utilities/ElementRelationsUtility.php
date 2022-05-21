<?php

namespace internetztube\elementRelations\utilities;

use Craft;
use craft\base\Utility;
use internetztube\elementRelations\jobs\CreateRefreshElementRelationsJobsJob;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\services\ElementRelationsService;

class ElementRelationsUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('element-relations', 'Element Relations');
    }

    public static function id(): string
    {
        return 'element-relations';
    }

    public static function iconPath(): string
    {
        return Craft::getAlias("@internetztube/elementRelations/icon-mask.svg");
    }

    public static function contentHtml(): string
    {
        $pushedQueueJob = false;
        $isCacheEnabled = CacheService::useCache();
        if (Craft::$app->request->getIsPost() && $isCacheEnabled) {
            $force = !!Craft::$app->request->getParam('force', false);
            $job = new CreateRefreshElementRelationsJobsJob(['force' => $force]);
            Craft::$app->getQueue()->delay(10)->priority(4096)->push($job);
            $pushedQueueJob = true;
        }

        $current = CacheService::getCountOfNonStaleElementRelations();
        $total = count(ElementRelationsService::getElementsWithElementRelationsField());
        $total = $current > $total ? $current : $total;
        $percentage = $total > 0 ? round($current * 100 / $total, 2) : 100;
        $cacheDuration = CacheService::getCacheDuration();

        return Craft::$app->getView()->renderTemplate(
            'element-relations/_utility',
            [
                'current' => $current,
                'total' => $total,
                'percentage' => $percentage,
                'pushed' => $pushedQueueJob,
                'isCacheEnabled' => $isCacheEnabled,
                'cacheDuration' => $cacheDuration,
            ]
        );
    }
}