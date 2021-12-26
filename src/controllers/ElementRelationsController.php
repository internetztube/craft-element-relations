<?php

namespace internetztube\elementRelations\controllers;

use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\ArrayHelper;
use craft\helpers\Cp;
use craft\web\Controller;
use fruitstudios\linkit\models\Url;
use GuzzleHttp\Client;
use internetztube\elementRelations\records\ElementRelationsRecord;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\services\ElementRelationsService;
use verbb\supertable\elements\SuperTableBlockElement;
use verbb\supertable\SuperTable;
use yii\web\NotFoundHttpException;

class ElementRelationsController extends Controller
{
    public    $enableCsrfValidation = false;
    protected $allowAnonymous       = true;
    protected $elementRelationsService;

    public function __construct($id, $module, $config = [])
    {
        parent::__construct($id, $module, $config);
        $this->elementRelationsService = new ElementRelationsService();
    }

    /**
     * @return string
     * @throws NotFoundHttpException
     */
    public function actionGetByElementId(): string
    {
        $elementId = Craft::$app->request->getParam('elementId');
        $siteId = Craft::$app->request->getParam('siteId');
        $size = Craft::$app->request->getParam('size', 'default');
        $size = $size === 'small' ? Cp::ELEMENT_SIZE_SMALL : Cp::ELEMENT_SIZE_LARGE;
        $element = Craft::$app->elements->getElementById($elementId, null, $siteId);
        if (!$element) throw new NotFoundHttpException;
        return CacheService::getRelationsCached($element, $size);
    }
}
