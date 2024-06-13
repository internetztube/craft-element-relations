<?php

namespace internetztube\elementRelations\services\fields;

use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Asset;
use Craft;
use internetztube\elementRelations\services\FieldLayoutUsageService;

class FieldSeomaticService implements InterfaceFieldService
{
    public static function getElementsSitesQuery(ElementInterface $element): ?Query
    {
        if (!Craft::$app->plugins->isPluginEnabled('seomatic') || !($element instanceof Asset)) {
            return null;
        }

        return FieldLayoutUsageService::getElementsSitesSearchQueryByFieldClass(
            \nystudio107\seomatic\fields\SeoSettings::class,
            "[\"$element->id\"]",
            ["metaBundleSettings", "seoImageIds"]
        );
    }
}