<?php

namespace internetztube\elementRelations\services\fields;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;

class FieldHyperService implements InterfaceFieldService
{
    public static function getElementsSitesQuery(ElementInterface $element): ?Query
    {
        if (!Craft::$app->plugins->isPluginEnabled('hyper')) {
            return null;
        }

        return (new Query())
            ->select("elements_sites.*")
            ->from(["hyper_element_cache" => \verbb\hyper\records\ElementCache::tableName()])
            ->innerJoin(["elements_sites" => Table::ELEMENTS_SITES], "
                [[hyper_element_cache.sourceId]] = [[elements_sites.elementId]] AND 
                [[hyper_element_cache.sourceSiteId]] = [[elements_sites.siteId]]
            ")
            ->where([
                "and",
                ["=", "hyper_element_cache.targetId", $element->id],
                ["=", "hyper_element_cache.targetSiteId", $element->siteId]
            ]);
    }
}