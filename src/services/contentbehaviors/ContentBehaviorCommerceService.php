<?php

namespace internetztube\elementRelations\services\contentbehaviors;

use Craft;
use craft\db\Query;
use craft\db\Table;

class ContentBehaviorCommerceService implements InterfaceContentBehaviourService
{
    private const TABLE_ALIAS_ELEMENTS = "alias_elements_for_commerce_variants";

    public static function getColumns(): array
    {
        if (!self::isInUse()) {
            return [];
        }

        $tableAliasElements = self::TABLE_ALIAS_ELEMENTS;
        return [
            self::getColumnElementsId() => "$tableAliasElements.id",
            self::getColumnElementsType() => "$tableAliasElements.type"
        ];
    }

    public static function enrichQuery(Query $query): Query
    {
        if (!self::isInUse()) {
            return $query;
        }

        $tableAliasElements = self::TABLE_ALIAS_ELEMENTS;
        $tableElements = Table::ELEMENTS;
        $tableCommerceVariants = \craft\commerce\db\Table::VARIANTS;
        return $query
            ->leftJoin($tableCommerceVariants, "$tableElements.id = $tableCommerceVariants.id")
            ->leftJoin([$tableAliasElements => $tableElements], "$tableCommerceVariants.id = $tableAliasElements.id");
    }

    public static function getColumnElementsId(): string
    {
        return "commerce_variants_primaryOwnerId_elements_elementId";
    }

    public static function getColumnElementsType(): string
    {
        return "commerce_variants_primaryOwnerId_elements_type";
    }

    private static function isInUse(): bool
    {
        return Craft::$app->plugins->isPluginEnabled("commerce");
    }
}