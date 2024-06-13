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

        return [
            self::getColumnElementsId() => self::TABLE_ALIAS_ELEMENTS . ".id",
            self::getColumnElementsType() => self::TABLE_ALIAS_ELEMENTS . ".type"
        ];
    }

    private static function isInUse(): bool
    {
        return Craft::$app->plugins->isPluginEnabled("commerce");
    }

    public static function getColumnElementsId(): string
    {
        return self::class . "_commerce_variants_primaryOwnerId_elements_elementId";
    }

    public static function getColumnElementsType(): string
    {
        return self::class . "_commerce_variants_primaryOwnerId_elements_type";
    }

    public static function enrichQuery(Query $query): Query
    {
        if (!self::isInUse()) {
            return $query;
        }

        return $query
            ->leftJoin(
                ["commerce_variants" => \craft\commerce\db\Table::VARIANTS],
                "[[elements_sites.elementId]] = [[commerce_variants.id]]"
            )
            ->leftJoin(
                [self::TABLE_ALIAS_ELEMENTS => Table::ELEMENTS],
                "[[commerce_variants.id]] = [[" . self::TABLE_ALIAS_ELEMENTS . ".id]]"
            );
    }
}