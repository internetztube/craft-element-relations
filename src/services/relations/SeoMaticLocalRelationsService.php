<?php

namespace internetztube\elementRelations\services\relations;

use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use internetztube\elementRelations\services\FieldLayoutUsageService;
use Craft;

class SeoMaticLocalRelationsService
{
    /**
     * @param Asset $asset
     * @return ElementInterface[]
     */
    public static function getReverseRelations(Asset $asset): array
    {
        if (!Craft::$app->plugins->isPluginEnabled('seomatic')) {
            return [];
        }
        $customFields = FieldLayoutUsageService::getCustomFieldsByFieldClass(nystudio107\seomatic\fields\SeoSettings::class);

        $queryBuilder = Craft::$app->getDb()->getQueryBuilder();
        $searchValue = "[\"$asset->id\"]";

        $tableElementsSites = Table::ELEMENTS_SITES;
        $tableElements = Table::ELEMENTS;

        $whereStatements = collect($customFields)
            ->flatten()
            ->pluck('uid')
            ->map(function (string $uid) use ($queryBuilder, $searchValue, $tableElementsSites) {
                $columnSelector = $queryBuilder->jsonExtract(
                    "$tableElementsSites.content",
                    [$uid, "metaBundleSettings", "seoImageIds"]
                );
                return ["=", $columnSelector, $searchValue];
            })
            ->toArray();


        $baseElementInfo = (new Query())
            ->select([
                "$tableElementsSites.elementId",
                "$tableElementsSites.siteId",
                "$tableElements.type"
            ])
            ->from($tableElementsSites)
            ->innerJoin($tableElements, "$tableElementsSites.elementId = $tableElements.id")
            ->where(["or", ...$whereStatements])
            ->all();

        return BaseRelationsService::getElementsByBaseElementInfo($baseElementInfo);
    }
}