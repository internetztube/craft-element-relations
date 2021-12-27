<?php

namespace internetztube\elementRelations\controllers;

use Craft;
use craft\web\Controller;
use fruitstudios\linkit\models\Url;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\services\ElementRelationsService;
use verbb\supertable\elements\SuperTableBlockElement;
use verbb\supertable\SuperTable;
use yii\web\NotFoundHttpException;

class ElementRelationsController extends Controller
{
    public $enableCsrfValidation = false;
    protected $allowAnonymous = true;

    public function actionGetByElementId(): string
    {
        $elementId = Craft::$app->request->getParam('elementId');
        $siteId = Craft::$app->request->getParam('siteId');
        $force = Craft::$app->request->getParam('force') === 'true';
        $element = ElementRelationsService::getElementById($elementId, $siteId);
        if (!$element) throw new NotFoundHttpException;
        return CacheService::getElementRelationsCached($element, $force);
    }
}
