<?php

namespace internetztube\elementRelations\services\fields;

use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;

class FieldHyperService implements InterfaceFieldService
{
    public static function getElementsSitesQuery(ElementInterface $element): ?Query
    {
        if (!\Craft::$app->plugins->isPluginEnabled('hyper')) {
            return null;
        }

        $tableElementsSites = Table::ELEMENTS_SITES;
        $tableHyperElementCache = \verbb\hyper\records\ElementCache::tableName();

        return (new Query())
            ->select("$tableElementsSites.*")
            ->from($tableHyperElementCache)
            ->innerJoin($tableElementsSites, "
                (
                    $tableHyperElementCache.sourceId = $tableElementsSites.elementId AND
                    $tableHyperElementCache.sourceSiteId = $tableElementsSites.siteId
                )
            ")
            ->where([
                "and",
                ["=", "$tableHyperElementCache.targetId", $element->id],
                ["=", "$tableHyperElementCache.targetSiteId", $element->siteId]
            ]);
    }
}