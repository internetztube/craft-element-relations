<?php

namespace internetztube\elementRelations\services\relations;

use craft\db\Query;
use craft\elements\Asset;
use nystudio107\seomatic\records\MetaBundle;
use Craft;

class SeoMaticGlobalRelationsService
{
    /**
     * @param Asset $asset
     * @return bool
     */
    public static function getReverseRelations(Asset $asset): bool
    {
        $queryBuilder = Craft::$app->getDb()->getQueryBuilder();
        $columnSelector = $queryBuilder->jsonExtract('metaBundleSettings', ['seoImageIds']);

        return (new Query())
            ->from([MetaBundle::tableName()])
            ->where(['=', $columnSelector, "[\"$asset->id\"]"])
            ->collect()
            ->isNotEmpty();
    }
}