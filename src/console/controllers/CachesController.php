<?php

namespace internetztube\elementRelations\console\controllers;

use Craft;
use internetztube\elementRelations\jobs\CreateRefreshElementRelationsJobsJob;
use yii\console\Controller;

/**
 * Element Relations Caching
 *
 * The first line of this class docblock is displayed as the description
 * of the Console Command in ./craft help
 *
 * Craft can be invoked via commandline console by using the `./craft` command
 * from the project root.
 *
 * ./craft element-relations/caches/index //not implemented yet
 * ./craft element-relations/caches/create
 * ./craft element-relations/caches/refresh
 *
 * @todo implement index action to at least not error out. Maybe indicate volumes/sections using the field?
 * @todo add siteId to calls to restrict to one site id
 * @todo move refresh and create calls to Jobs so they enqueue and run when available
 *
 */
class CachesController extends Controller
{
    /**
     * @var bool Whether caches should be rebuilt, even if they already exist
     * @since 1.0.7
     */
    public $force = false;

    /**
     * @inheritdoc
     * These options will be passed through from the command line to this controller
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'force';
        return $options;
    }

    /**
     * Refresh existing relations without looking for new ones
     * Looks up all the relations in the table and refreshes if stale or forced
     * @return bool
     */
    public function actionRefresh()
    {
        $job = new CreateRefreshElementRelationsJobsJob(['force' => $this->force]);
        Craft::$app->getQueue()->push($job);
        $this->stdout('Pushed Create Element Relations Cache Refresh Tasks into Queue' . PHP_EOL);
    }
}
