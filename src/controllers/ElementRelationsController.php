<?php

namespace internetztube\elementRelations\controllers;

use Craft;
use craft\web\Controller;
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
}
