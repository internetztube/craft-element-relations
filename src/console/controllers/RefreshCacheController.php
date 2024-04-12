<?php

namespace internetztube\elementRelations\console\controllers;

use Craft;
use internetztube\elementRelations\jobs\CreateRefreshElementRelationsJobsJob;
use yii\console\Controller;

/**
 * Refresh Element Relations Cache
 * php craft element-relations/refresh-cache
 */
class RefreshCacheController extends Controller
{
    /**
     * Refresh existing relations without looking for new ones.
     * Looks up all the relations in the table and refreshes them.
     */
    public function actionIndex()
    {
        $job = new CreateRefreshElementRelationsJobsJob();
        Craft::$app->getQueue()->delay(10)->priority(4090)->push($job);
        $this->stdout('Successfully pushed CreateRefreshElementRelationsJobsJob into Queue' . PHP_EOL);
    }
}
