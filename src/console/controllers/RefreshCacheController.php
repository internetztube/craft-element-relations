<?php

namespace internetztube\elementRelations\console\controllers;

use Craft;
use internetztube\elementRelations\jobs\CreateRefreshElementRelationsJobsJob;
use yii\console\Controller;

/**
 * Refresh Element Relations Cache
 * php craft element-relations/refresh-cache
 * php craft element-relations/refresh-cache --force
 */
class RefreshCacheController extends Controller
{
    /**
     * Whether caches should be rebuilt, even if they already exist
     * @var bool
     * @since 1.1.0
     */
    public $force = false;

    /**
     * @param string $actionID
     * @return array
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'force';
        return $options;
    }

    /**
     * Refresh existing relations without looking for new ones.
     * Looks up all the relations in the table and refreshes if stale or forced.
     */
    public function actionIndex()
    {
        $job = new CreateRefreshElementRelationsJobsJob(['force' => $this->force]);
        Craft::$app->getQueue()->delay(10)->priority(4000)->push($job);
        $this->stdout('Successfully pushed CreateRefreshElementRelationsJobsJob into Queue' . PHP_EOL);
    }
}
