<?php

namespace internetztube\elementRelations\services\extractors;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Asset;

class SpecialExtractorSeomaticGlobalService
{
    public static function isInUse(ElementInterface $element): bool
    {
        if (!Craft::$app->plugins->isPluginEnabled('seomatic') || !($element instanceof Asset)) {
            return false;
        }
        $queryBuilder = Craft::$app->getDb()->getQueryBuilder();
        $columnSelector = $queryBuilder->jsonExtract("metaBundleSettings", ["seoImageIds"]);

        return (new Query())
            ->from([\nystudio107\seomatic\records\MetaBundle::tableName()])
            ->where(['=', $columnSelector, "[\"$element->id\"]"])
            ->collect()
            ->isNotEmpty();
    }
}