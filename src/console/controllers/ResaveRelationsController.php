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
use craft\helpers\Db;
use Illuminate\Support\Collection;
use internetztube\elementRelations\jobs\GenerateResaveAllElementRelationsJobsJob;
use internetztube\elementRelations\jobs\ResaveMultipleElementRelationsJob;
use yii\console\ExitCode;

/**
 * Resave Relations controller
 */
class ResaveRelationsController extends Controller
{
    public function actionIndex(): int
    {

        $job = new GenerateResaveAllElementRelationsJobsJob();
        Craft::$app->getQueue()->priority(1022)->push($job);
        $this->stdout("Pushed Job into Queue." . PHP_EOL);
        return ExitCode::OK;
    }
}
