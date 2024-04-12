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
    protected array|int|bool $allowAnonymous = false;

    public function actionGetByElementId()
    {
        $elementId = (int)Craft::$app->request->getParam('elementId');
        $siteId = Craft::$app->request->getParam('siteId');
        $isElementDetail = Craft::$app->request->getParam('isElementDetail') === 'true';
        $refresh = Craft::$app->request->getParam('refresh') === 'true';

        $priority = 100;
        if ($refresh) {
            $priority = 99;
            CacheService::deleteElementRelationsRecord($elementId);
        }
        $elementRelations = CacheService::getElementRelations($elementId, $priority);

        if (is_null($elementRelations)) {
            $statusQueue = RefreshElementRelationsJob::getQueueStatus($elementId);
            return $this->asJson([
                'statusQueue' => $statusQueue,
                'content' => Craft::$app->getView()->renderTemplate(
                    'element-relations/_components/fields/relations-wrapper',
                    [
                        'statusQueue' => $statusQueue,
                        'isElementDetail' => $isElementDetail,
                    ]
                )
            ]);
        }

        $markup = MarkupService::getMarkupFromElementRelations($elementRelations, $elementId, $siteId, $isElementDetail);
        return $this->asJson([
            'statusQueue' => 'not-found',
            'content' => $markup
        ]);
    }
}
