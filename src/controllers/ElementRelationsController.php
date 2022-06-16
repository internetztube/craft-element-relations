<?php

namespace internetztube\elementRelations\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\i18n\Locale;
use craft\web\Controller;
use internetztube\elementRelations\jobs\RefreshElementRelationsJob;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\services\MarkupService;

class ElementRelationsController extends Controller
{
    public $enableCsrfValidation = false;
    protected array|int|bool $allowAnonymous = true;

    public function actionGetByElementId(): string
    {
        $elementId = (int) Craft::$app->request->getParam('elementId');
        $siteId = Craft::$app->request->getParam('siteId');
        $force = Craft::$app->request->getParam('force') === 'true';
        $elementRelations = CacheService::getElementRelationsCached($elementId, $force);
        $markup = MarkupService::getMarkupFromElementRelations($elementRelations, $elementId, $siteId);
        $result = $markup;
        if (CacheService::useCache()) {
            $dateUpdated = CacheService::getDateUpdatedFromElementRelations($elementId);
            $dateUpdated = DateTimeHelper::toDateTime($dateUpdated)
                ->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()))
                ->format(Craft::$app->getFormattingLocale()->getDateTimeFormat(Locale::LENGTH_LONG, Locale::FORMAT_PHP));
            $markupDate = sprintf('<span class="info element-relations-lazy-hidden">%s %s</span>', Craft::t('element-relations', 'field-value-last-update'), $dateUpdated);
            $markupReloadButton = sprintf('<button type="button" class="btn small js-element-relations-reload element-relations-lazy-hidden">%s</button>', Craft::t('element-relations', 'field-value-button-reload'));
            $markupRefreshButton = sprintf('<button type="button" class="btn small js-element-relations-refresh element-relations-lazy-hidden">%s</button>', Craft::t('element-relations', 'field-value-button-refresh'));
            $style = '<style>.element-relations-lazy-hidden { display: none; }</style>';
            $result .= '<br />' . $markupReloadButton . ' ' . $markupRefreshButton . ' ' . $markupDate . ' ' . $style;
        }
        return $result;
    }

    public function actionRefreshByElementId(): string
    {
        $elementId = (int)Craft::$app->request->getParam('elementId');
        RefreshElementRelationsJob::createJob($elementId);
        return 'true';
    }
}
