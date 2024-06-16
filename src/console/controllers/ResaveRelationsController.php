<?php

namespace internetztube\elementRelations\console\controllers;

use benf\neo\records\Block;
use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\console\Controller;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use craft\elements\db\ElementQueryInterface;
use Illuminate\Support\Collection;
use internetztube\elementRelations\jobs\ResaveAllElementRelationsJob;
use internetztube\elementRelations\services\RelationsExtractorService;
use internetztube\elementRelations\services\ResaveRelationsService;
use yii\console\ExitCode;

/**
 * Resave Relations controller
 */
class ResaveRelationsController extends Controller
{
    /**
     * element-relations/resave-relations command
     */
    public function actionIndex(): int
    {
        Craft::$app->queue->priority(1023)->push(new ResaveAllElementRelationsJob());
        return ExitCode::OK;
        ResaveRelationsService::resave(function ($index, $totalCount, $elementType) {
            $message = sprintf("%5s / %5s - %20s", $index, $totalCount, $elementType);
            $this->stdout($message . PHP_EOL);
        });
        return ExitCode::OK;
    }
}
