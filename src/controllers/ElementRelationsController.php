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
        $isUsedInSEOmatic = ElementRelationsService::isUsedInSEOmatic($element);

        $result = collect();

        if ($isUsedInSEOmatic['usedGlobally']) {
            $result->push('Used in SEOmatic Global Settings');
        }
        if (!empty($isUsedInSEOmatic['elements'])) {
            $result->push('Used in SEOmatic in these Elements (+Drafts):');
            $result->push(Cp::elementPreviewHtml($isUsedInSEOmatic['elements'], $size));
        }
        if (!empty($relations)) {
            $result->push(Cp::elementPreviewHtml($relations, $size));
        } else {
            $relationsAnySite = ElementRelationsService::getRelationsFromElement($element, true);
            if (!empty($relationsAnySite)) {
                $result->push('Unused in this site, but used in others:');
                $result->push(Cp::elementPreviewHtml($relationsAnySite, $size));
            }
        }

        if ($result->isEmpty()) { $result->push('<span style="color: #da5a47;">Unused</span>'); }
        return $result->implode('<br />');
    }
}
