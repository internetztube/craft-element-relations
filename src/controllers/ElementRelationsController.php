<?php

namespace internetztube\elementRelations\controllers;

use Craft;
use craft\web\Controller;
use fruitstudios\linkit\models\Url;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\services\MarkupService;
use verbb\supertable\elements\SuperTableBlockElement;
use verbb\supertable\SuperTable;

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
