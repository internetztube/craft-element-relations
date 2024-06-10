<?php

namespace internetztube\elementRelations\services\relations;

use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use Craft;
use internetztube\elementRelations\services\FieldLayoutUsageService;

class GenericRelationsService
{
    /**
     * @param ElementInterface $element
     * @return ElementInterface[]
     */
    public static function getReverseRelations(ElementInterface $element): array
    {
        $elementsSitesUnionQuery = (new Query())->from(
            self::getElementsSitesQueryForRelationsTable($element)
        );
        if ($elementsSitsQueryForHyper = self::getElementsSitesQueryForHyper($element)) {
            $elementsSitesUnionQuery->union($elementsSitsQueryForHyper);
        }

        if ($elementsSitsQueryForLinkIt = self::getElementsSitesQueryForLinkIt($element)) {
            // slow
            $elementsSitesUnionQuery->union($elementsSitsQueryForLinkIt);
        }

        return BaseRelationsService::getElementsByBaseElementInfo(
            self::getReverseRelationsForElementsSitesQuery($elementsSitesUnionQuery)
        );
    }

    /**
     * @param ElementInterface $element
     * @return Query
     */
    private static function getElementsSitesQueryForRelationsTable(ElementInterface $element): Query
    {
        $tableRelations = Table::RELATIONS;
        $tableElementsSites = Table::ELEMENTS_SITES;

        return (new Query())
            ->select("$tableElementsSites.*")
            ->from($tableRelations)
            ->innerJoin($tableElementsSites, "
                (
                    ($tableRelations.sourceId = $tableElementsSites.elementId AND $tableRelations.sourceSiteId IS NULL) OR 
                    ($tableRelations.sourceId = $tableElementsSites.elementId AND $tableRelations.sourceSiteId = $tableElementsSites.siteId)
                )
            ")
            ->where(['and', ['=', "$tableRelations.targetId", $element->id]]);
    }

    /**
     * @param ElementInterface $element
     * @return Query|null
     */
    private static function getElementsSitesQueryForLinkIt(ElementInterface $element): ?Query
    {
        if (!Craft::$app->plugins->isPluginEnabled('linkit')) {
            return null;
        }
        $customFields = FieldLayoutUsageService::getCustomFieldsByFieldClass(\fruitstudios\linkit\fields\LinkitField::class);

        $queryBuilder = Craft::$app->getDb()->getQueryBuilder();
        $searchValue = $element->id;
        $tableElementsSites = Table::ELEMENTS_SITES;
        $whereStatements = collect($customFields)
            ->flatten()
            ->pluck('uid')
            ->map(function (string $uid) use ($queryBuilder, $searchValue, $tableElementsSites) {
                $columnSelector = $queryBuilder->jsonExtract("$tableElementsSites.content", [$uid, "value"]);
                return ["=", $columnSelector, $searchValue];
            })
            ->toArray();

        return (new Query())
            ->select("$tableElementsSites.*")
            ->from($tableElementsSites)
            ->where(["or", ...$whereStatements]);
    }

    /**
     * @param ElementInterface $element
     * @return Query|null
     */
    private static function getElementsSitesQueryForHyper(ElementInterface $element): ?Query
    {
        if (!Craft::$app->plugins->isPluginEnabled('hyper')) {
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

    /**
     * @param Query $elementsSitesQuery
     * @return array BaseElementInfo
     */
    private static function getReverseRelationsForElementsSitesQuery(Query $elementsSitesQuery): array
    {
        $tableElements = Table::ELEMENTS;
        $tableElementsSites = Table::ELEMENTS_SITES;
        $tableEntries = Table::ENTRIES;
        $aliasTableElementsForEntries = "alias_elements_for_entries";
        $aliasTableElementsForNeoblocks = "alias_elements_for_neoblocks";
        $aliasTableElementsForCommerceVariants = "alias_elements_for_commerce_variants";

        $query = (new Query())
            ->select([
                "$tableElementsSites.elementId",
                "$tableElementsSites.siteId",
                "$tableElements.type",

                // Matrix
                "entries_primaryOwnerId_elements_elementId" => "$aliasTableElementsForEntries.id",
                "entries_primaryOwnerId_elements_type" => "$aliasTableElementsForEntries.type",

                // NEO
                ...(self::isPluginNeoEnabled() ? [
                    "neoblocks_primaryOwnerId_elements_elementId" => "$aliasTableElementsForNeoblocks.id",
                    "neoblocks_primaryOwnerId_elements_type" => "$aliasTableElementsForNeoblocks.type"
                ] : []),

                // Commerce Variants
                ...(self::isPluginCommerceEnabled() ? [
                    "commerce_variants_primaryOwnerId_elements_elementId" => "$aliasTableElementsForCommerceVariants.id",
                    "commerce_variants_primaryOwnerId_elements_type" => "$aliasTableElementsForCommerceVariants.type"
                ] : [])
            ])
            ->from([$tableElementsSites => $elementsSitesQuery])
            ->innerJoin($tableElements, "$tableElementsSites.elementId = $tableElements.id")

            // Matrix
            ->leftJoin($tableEntries, "$tableElements.id = $tableEntries.id")
            ->leftJoin([$aliasTableElementsForEntries => $tableElements], "$tableEntries.primaryOwnerId = $aliasTableElementsForEntries.id");

        if (self::isPluginNeoEnabled()) {
            $tableNeoblocks = \benf\neo\records\Block::tableName();
            $query
                ->leftJoin($tableNeoblocks, "$tableElements.id = $tableNeoblocks.id")
                ->leftJoin([$aliasTableElementsForNeoblocks => $tableElements], "$tableNeoblocks.primaryOwnerId = $aliasTableElementsForNeoblocks.id");
        }

        if (self::isPluginCommerceEnabled()) {
            $tableCommerceVariants = \craft\commerce\db\Table::VARIANTS;
            $query
                ->leftJoin($tableCommerceVariants, "$tableElements.id = $tableCommerceVariants.id")
                ->leftJoin([$aliasTableElementsForCommerceVariants => $tableElements], "$tableCommerceVariants.id = $aliasTableElementsForCommerceVariants.id");
        }

        return $query->collect()
            ->map(fn(array $row) => [
                "elementId" =>
                    $row["entries_primaryOwnerId_elements_elementId"] ??
                        $row["neoblocks_primaryOwnerId_elements_elementId"] ??
                        $row["elementId"],
                "type" =>
                    $row["entries_primaryOwnerId_elements_type"] ??
                        $row["neoblocks_primaryOwnerId_elements_type"] ??
                        $row["type"],
                "siteId" => $row["siteId"],
            ])
            ->all();
    }

    private static function isPluginNeoEnabled(): bool
    {
        return Craft::$app->plugins->isPluginEnabled("neo");
    }

    private static function isPluginCommerceEnabled(): bool
    {
        return Craft::$app->plugins->isPluginEnabled("commerce");
    }
}