<?php

namespace internetztube\elementRelations\services\contentbehaviors;

use craft\db\Query;
use craft\db\Table;

class ContentBehaviorMatrixService implements InterfaceContentBehaviourService
{
    private const TABLE_ALIAS_ELEMENTS = "alias_elements_for_entries";

    public static function getColumns(): array
    {
        $tableAliasElements = self::TABLE_ALIAS_ELEMENTS;
        return [
            self::getColumnElementsId() => "$tableAliasElements.id",
            self::getColumnElementsType() => "$tableAliasElements.type",
        ];
    }

    public static function enrichQuery(Query $query): Query
    {
        $tableAliasElements = self::TABLE_ALIAS_ELEMENTS;
        $tableElements = Table::ELEMENTS;
        $tableEntries = Table::ENTRIES;
        return $query
            ->leftJoin($tableEntries, "$tableElements.id = $tableEntries.id")
            ->leftJoin([$tableAliasElements => $tableElements], "$tableEntries.primaryOwnerId = $tableAliasElements.id");
    }

    public static function getColumnElementsId(): string
    {
        return self::class . "entries_primaryOwnerId_elements_elementId";
    }

    public static function getColumnElementsType(): string
    {
        return "entries_primaryOwnerId_elements_type";
    }
}