<?php

namespace internetztube\elementRelations\services\fields;

use craft\base\ElementInterface;
use craft\db\Query;
use internetztube\elementRelations\services\FieldLayoutUsageService;

class FieldLinkItService implements InterfaceFieldService
{
    public static function getElementsSitesQuery(ElementInterface $element): ?Query
    {
        if (!\Craft::$app->plugins->isPluginEnabled('linkit')) {
            return null;
        }

        return FieldLayoutUsageService::getElementsSitesSearchQueryByFieldClass(
            \fruitstudios\linkit\fields\LinkitField::class,
            $element->id,
            ["value"]
        );
    }
}