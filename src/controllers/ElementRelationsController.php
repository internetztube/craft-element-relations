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

class ElementRelationsController extends Controller
{
    protected $allowAnonymous = true;
    public $enableCsrfValidation = false;

    public function actionGetByElementId()
    {
        $elementId = \Craft::$app->request->getParam('elementId');
        $siteId = \Craft::$app->request->getParam('siteId');
        $size = \Craft::$app->request->getParam('size', 'default');
        $size = $size === 'small' ? Cp::ELEMENT_SIZE_SMALL : Cp::ELEMENT_SIZE_LARGE;
        $element = \Craft::$app->elements->getElementById($elementId, null, $siteId);
        if (!$element) throw new NotFoundHttpException;
        $relations = ElementRelationsService::getRelationsFromElement($element);

        if (!count($relations)) {
            $relationsAnySite = ElementRelationsService::getRelationsFromElement($element, true);
            if (count($relationsAnySite) === 0) {
                return '<span style="color: #da5a47;">Unused</span>';
            } else {
                $result = 'Unused in this site, but used in others:<br />';
                $result .= Cp::elementPreviewHtml($relationsAnySite, $size);
                return $result;
            }
        }
        return Cp::elementPreviewHtml($relations, $size);
    }
}
