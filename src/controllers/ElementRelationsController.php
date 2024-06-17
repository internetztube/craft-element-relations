<?php

namespace internetztube\elementRelations\controllers;

use Craft;
use craft\web\Controller;
use internetztube\elementRelations\jobs\RefreshElementRelationsJob;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\services\extractors\SpecialExtractorSeomaticGlobalService;
use internetztube\elementRelations\services\MarkupService;
use internetztube\elementRelations\services\RelationsService;

class ElementRelationsController extends Controller
{
    public $enableCsrfValidation = false;
    protected array|int|bool $allowAnonymous = false;

    public function actionGetByElementId()
    {
        $elementId = (int)Craft::$app->request->getParam('elementId');
        $siteId = Craft::$app->request->getParam('siteId');
        $isPreview = Craft::$app->request->getParam('is-preview') === 'true';

        $element = Craft::$app->elements->getElementById($elementId, null, $siteId);
        $template = 'element-relations/_components/fields/relations' . ($isPreview ? '_preview' : '');

        return Craft::$app->getView()->renderTemplate($template, [
            'relations' => RelationsService::getRelations($element),
            'seomaticGlobal' => SpecialExtractorSeomaticGlobalService::isInUse($element),
        ]);
    }
}
