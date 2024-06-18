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
use internetztube\elementRelations\services\ResaveRelationsService;
use yii\console\ExitCode;

/**
 * Resave Relations controller
 */
class ResaveRelationsController extends Controller
{
    public bool $queue = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = "queue";
        return $options;
    }

    /**
     * element-relations/resave-relations command
     */
    public function actionIndex(): int
    {
        if ($this->queue) {
            $job = new ResaveAllElementRelationsJob();
            Craft::$app->getQueue()->priority(1022)->push($job);
            $this->stdout("Pushed Job into Queue." . PHP_EOL);
        } else {
            $this->stdout("Find all elements ..." . PHP_EOL);

            ResaveRelationsService::resave(function (int $index, int $totalCount, string $elementType) {
                $message = sprintf("%3s%% - %5s / %5s - %20s", (int) ($index * 100 / $totalCount), $index, $totalCount, $elementType);
                $this->stdout($message . PHP_EOL);
            });
        }

        return ExitCode::OK;
    }
}
