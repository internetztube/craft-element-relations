<?php

namespace internetztube\elementRelations\services\contentbehaviors;

use Craft;
use craft\db\Query;
use craft\db\Table;

class ContentBehaviorNeoService implements InterfaceContentBehaviourService
{
    private const TABLE_ALIAS_ELEMENTS = "alias_elements_for_neoblocks";

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

        $tableNeoblocks = \benf\neo\records\Block::tableName();
        $tableAliasElements = self::TABLE_ALIAS_ELEMENTS;
        $tableElements = Table::ELEMENTS;
        return $query
            ->leftJoin($tableNeoblocks, "$tableElements.id = $tableNeoblocks.id")
            ->leftJoin([$tableAliasElements => $tableElements], "$tableNeoblocks.primaryOwnerId = $tableAliasElements.id");
    }

    public static function getColumnElementsId(): string
    {
        return "neoblocks_primaryOwnerId_elements_elementId";
    }

    public static function getColumnElementsType(): string
    {
        return "neoblocks_primaryOwnerId_elements_type";
    }

    private static function isInUse(): bool
    {
        return Craft::$app->plugins->isPluginEnabled("neo");
    }
}