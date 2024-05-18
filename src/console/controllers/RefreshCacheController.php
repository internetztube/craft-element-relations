<?php

namespace internetztube\elementRelations\console\controllers;

use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\models\FieldLayout;
use internetztube\elementRelations\fields\ElementRelationsField;
use internetztube\elementRelations\jobs\CreateRefreshElementRelationsJobsJob;
use internetztube\elementRelations\services\FieldLayoutUsageService;
use internetztube\elementRelations\services\ProfilePhotoService;
use internetztube\elementRelations\services\SeomaticGlobalService;
use internetztube\elementRelations\services\SeomaticLocalRelationsService;
use internetztube\elementRelations\services\SeomaticService;
use internetztube\elementRelations\services\UserPhotoRelationService;
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

    public function actionDummy()
    {
        $seomaticLocalRelationsService = new SeomaticLocalRelationsService();
        $seomaticLocalRelationsService->updateRelations();
        die();

        $asset = Asset::find()->id(14895)->one();
        $profilePhotoService = new UserPhotoRelationService();
        $profilePhotoService->updateRelationsForPhoto($asset);

        die();
        $seomatic = new SeomaticGlobalService();
        $seomatic->updateRelations();
    }
}
