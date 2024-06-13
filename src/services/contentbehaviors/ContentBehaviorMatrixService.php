<?php

namespace internetztube\elementRelations\services\contentbehaviors;

use craft\db\Query;
use craft\db\Table;

class ContentBehaviorMatrixService implements InterfaceContentBehaviourService
{
    private const TABLE_ALIAS_ELEMENTS = "alias_elements_for_entries";

    public static function getColumns(): array
    {
        return [
            self::getColumnElementsId() => self::TABLE_ALIAS_ELEMENTS . ".id",
            self::getColumnElementsType() => self::TABLE_ALIAS_ELEMENTS . ".type",
        ];
    }

    public static function getColumnElementsId(): string
    {
        return self::class . "_entries_primaryOwnerId_elements_elementId";
    }

    public static function getColumnElementsType(): string
    {
        return self::class . "_entries_primaryOwnerId_elements_type";
    }

    public static function enrichQuery(Query $query): Query
    {
        return $query
            ->leftJoin(
                ['entries' => Table::ENTRIES],
                "[[elements_sites.elementId]] = [[entries.id]]"
            )
            ->leftJoin(
                [self::TABLE_ALIAS_ELEMENTS => Table::ELEMENTS],
                "[[entries.primaryOwnerId]] = [[" . self::TABLE_ALIAS_ELEMENTS . ".id]]"
            );
    }
}