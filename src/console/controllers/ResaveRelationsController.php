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
        $this->stdout("Find all elements ..." . PHP_EOL);

        ResaveRelationsService::resave(function ($index, $totalCount, $elementType) {
            $message = sprintf("%3s%% - %5s / %5s - %20s", (int) ($index * 100 / $totalCount), $index, $totalCount, $elementType);
            $this->stdout($message . PHP_EOL);
        });
        return ExitCode::OK;
    }
}
