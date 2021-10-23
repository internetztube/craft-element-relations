<?php

namespace internetztube\elementRelations\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\Cp;
use craft\web\Controller;
use fruitstudios\linkit\models\Url;
use GuzzleHttp\Client;
use internetztube\elementRelations\services\ElementRelationsService;
use verbb\supertable\elements\SuperTableBlockElement;
use verbb\supertable\SuperTable;
use yii\base\BaseObject;
use yii\helpers\Markdown;
use yii\web\NotFoundHttpException;

class MainController extends Controller
{
    protected $allowAnonymous = true;
    public $enableCsrfValidation = false;

    public function actionGetByElementId()
    {
        $elementId = \Craft::$app->request->getParam('elementId');
        $siteId = \Craft::$app->request->getParam('siteId');
        $size = \Craft::$app->request->getParam('size', 'default');
        $element = \Craft::$app->elements->getElementById($elementId, null, $siteId);
        if (!$element) throw new NotFoundHttpException;
        $relations = ElementRelationsService::getRelationsFromElement($element);
        if (!count($relations)) { return '<span style="color: #da5a47;">Unused</span>'; }
        if ($size === 'small') {
            return Cp::elementPreviewHtml($relations, Cp::ELEMENT_SIZE_SMALL);
        }
        return Cp::elementPreviewHtml($relations, Cp::ELEMENT_SIZE_LARGE);

    }
}
