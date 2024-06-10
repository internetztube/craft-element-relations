<?php

namespace internetztube\elementRelations\services\relations;

use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use Craft;
use verbb\hyper\records\ElementCache as HyperElementCacheRecord;

class GenericRelationsService
{
    /**
     * @param ElementInterface $element
     * @return ElementInterface[]
     */
    public static function getReverseRelations(ElementInterface $element): array
    {
        return BaseRelationsService::getElementsByBaseElementInfo([
            ...self::getReverseRelationsForRelationsTable($element),
            ...self::getReverseRelationsForHyper($element)
        ]);
    }

    /**
     * @param ElementInterface $element
     * @return array BaseElementInfo
     */
    private static function getReverseRelationsForRelationsTable(ElementInterface $element): array
    {
        $tableElements = Table::ELEMENTS;
        $tableRelations = Table::RELATIONS;
        $tableElementsSites = Table::ELEMENTS_SITES;
        $tableEntries = Table::ENTRIES;
        $aliasTableElementForEntries = "alias_elements_for_entries";

        return (new Query())
            ->select([
                "$tableElementsSites.elementId",
                "$tableElementsSites.siteId",
                "$tableElements.type",

                // Matrix
                "entries_primaryOwnerId_elements_elementId" => "$aliasTableElementForEntries.id",
                "entries_primaryOwnerId_elements_type" => "$aliasTableElementForEntries.type",
            ])
            ->from($tableRelations)
            ->innerJoin($tableElementsSites, "
                (
                    ($tableRelations.sourceId = $tableElementsSites.elementId AND $tableRelations.sourceSiteId IS NULL) OR 
                    ($tableRelations.sourceId = $tableElementsSites.elementId AND $tableRelations.sourceSiteId = $tableElementsSites.siteId)
                )
            ")
            ->innerJoin($tableElements, "$tableElementsSites.elementId = $tableElements.id")

            // Matrix
            ->leftJoin($tableEntries, "$tableElements.id = $tableEntries.id")
            ->leftJoin([$aliasTableElementForEntries => $tableElements], "$tableEntries.primaryOwnerId = $aliasTableElementForEntries.id")

            // @TODO neo blocks
            ->where(['and', ['=', "$tableRelations.targetId", $element->id]])
            ->collect()
            ->map(fn(array $row) => [
                'elementId' => $row['entries_primaryOwnerId_elements_elementId'] ?? $row['elementId'],
                'siteId' => $row['siteId'],
                'type' => $row['entries_primaryOwnerId_elements_type'] ?? $row['type'],
            ])
            ->all();
    }

    /**
     * @param ElementInterface $element
     * @return array BaseElementInfo
     */
    private static function getReverseRelationsForHyper(ElementInterface $element): array
    {
        if (!Craft::$app->plugins->isPluginEnabled('hyper')) {
            return [];
        }
        $tableHyperElementCache = HyperElementCacheRecord::tableName();
        $tableElementsSites = Table::ELEMENTS_SITES;
        $tableElements = Table::ELEMENTS;
        $tableEntries = Table::ENTRIES;
        $aliasTableElementForEntries = "alias_elements_for_entries";

        return (new Query())
            ->select([
                "$tableElementsSites.elementId",
                "$tableElementsSites.siteId",
                "$tableElements.type",

                // Matrix
                "entries_primaryOwnerId_elements_elementId" => "$aliasTableElementForEntries.id",
                "entries_primaryOwnerId_elements_type" => "$aliasTableElementForEntries.type",
            ])
            ->from($tableHyperElementCache)
            ->innerJoin($tableElementsSites, "
                (
                    $tableHyperElementCache.sourceId = $tableElementsSites.elementId AND
                    $tableHyperElementCache.sourceSiteId = $tableElementsSites.siteId
                )
            ")
            ->innerJoin($tableElements, "$tableElementsSites.elementId = $tableElements.id")

            // Matrix
            ->leftJoin($tableEntries, "$tableElements.id = $tableEntries.id")
            ->leftJoin([$aliasTableElementForEntries => $tableElements], "$tableEntries.primaryOwnerId = $aliasTableElementForEntries.id")

            // @TODO neo blocks
            ->where([
                'and',
                ['=', "$tableHyperElementCache.targetId", $element->id],
                ['=', "$tableHyperElementCache.targetSiteId", $element->siteId]
            ])
            ->collect()
            ->map(fn(array $row) => [
                'elementId' => $row['entries_primaryOwnerId_elements_elementId'] ?? $row['elementId'],
                'siteId' => $row['siteId'],
                'type' => $row['entries_primaryOwnerId_elements_type'] ?? $row['type'],
            ])
            ->all();
    }
}