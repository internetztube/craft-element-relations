<?php

namespace internetztube\elementRelations\controllers;

use Craft;
use craft\web\Controller;
use internetztube\elementRelations\jobs\RefreshElementRelationsJob;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\services\MarkupService;

class ElementRelationsController extends Controller
{
    public $enableCsrfValidation = false;
    protected $allowAnonymous = true;

    public function actionGetByElementId(): string
    {
        $elementId = Craft::$app->request->getParam('elementId');
        $siteId = Craft::$app->request->getParam('siteId');
        $force = Craft::$app->request->getParam('force') === 'true';
        $elementRelations = CacheService::getElementRelationsCached($elementId, $force);
        return MarkupService::getMarkupFromElementRelations($elementRelations, $elementId, $siteId);
    }

    public function actionRefreshByElementId(): string
    {
        $elementId = (int) Craft::$app->request->getParam('elementId');
        $job = new RefreshElementRelationsJob([
            'elementIds' => [$elementId],
            'force' => true,
        ]);
        return Craft::$app->queue->push($job);
    }
}
