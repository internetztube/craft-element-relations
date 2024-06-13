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

        return [
            self::getColumnElementsId() => self::TABLE_ALIAS_ELEMENTS . ".id",
            self::getColumnElementsType() => self::TABLE_ALIAS_ELEMENTS . ".type"
        ];
    }

    private static function isInUse(): bool
    {
        return Craft::$app->plugins->isPluginEnabled("neo");
    }

    public static function getColumnElementsId(): string
    {
        return self::class . "_neoblocks_primaryOwnerId_elements_elementId";
    }

    public static function getColumnElementsType(): string
    {
        return self::class . "_neoblocks_primaryOwnerId_elements_type";
    }

    public static function enrichQuery(Query $query): Query
    {
        if (!self::isInUse()) {
            return $query;
        }

        return $query
            ->leftJoin(
                ['neoblocks' => \benf\neo\records\Block::tableName()],
                "[[elements_sites.elementId]] = [[neoblocks.id]]"
            )
            ->leftJoin(
                [self::TABLE_ALIAS_ELEMENTS => Table::ELEMENTS],
                "[[neoblocks.primaryOwnerId]] = [[" . self::TABLE_ALIAS_ELEMENTS . ".id]]"
            );
    }
}