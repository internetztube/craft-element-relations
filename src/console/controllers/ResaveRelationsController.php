<?php

declare(strict_types=1);

namespace internetztube\elementRelations\console\controllers;

use Craft;
use craft\console\Controller;
use internetztube\elementRelations\jobs\GenerateResaveAllElementRelationsJobsJob;
use yii\console\ExitCode;

/**
 * Rebuilds the element relations cache via the queue.
 */
class ResaveRelationsController extends Controller
{
    /**
     * Pushes a job into the queue that refreshes the relations cache of every element.
     */
    public function actionIndex(): int
    {
        Craft::$app->getQueue()->priority(1022)->push(new GenerateResaveAllElementRelationsJobsJob());
        $this->stdout("Pushed Job into Queue." . PHP_EOL);
        return ExitCode::OK;
    }
}
