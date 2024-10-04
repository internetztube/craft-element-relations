<?php

namespace internetztube\elementRelations\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use craft\models\Site;
use Illuminate\Support\Collection;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;
use internetztube\elementRelations\services\extractors\FieldExtractorCkEditorService;

class RelationsService
{
    /**
     * @param ElementInterface $element
     * @return ElementInterface[]
     */
    public static function getRelations(ElementInterface $element): array
    {
        return (new Query())
            ->select([
                "elementId" => 'elementrelations_cache.sourcePrimaryOwnerId',
                "siteId" => 'elementrelations_cache.sourceSiteId',
                "type" => 'elements.type'
            ])
            ->from(['elementrelations_cache' => ElementRelationsCacheRecord::tableName()])
            ->leftJoin(['elements' => Table::ELEMENTS], "[[elements.id]] = [[elementrelations_cache.sourcePrimaryOwnerId]]")
            ->where([
                'and',
                ['=', 'elementrelations_cache.targetElementId', $element->id],
                ['=', 'elementrelations_cache.targetSiteId', $element->siteId],
            ])
            ->collect()
            ->filter(fn (array $row) => $row['type'])
            ->groupBy('type')
            ->map(function (Collection $items, string $elementType) {
                $whereStatements = $items->map(fn($value) => [
                    "elements.id" => $value["elementId"],
                    "elements_sites.siteId" => $value["siteId"]
                ]);

                /** @var ElementQuery $query */
                $query = $elementType::find();
                $query->where(["or", ...$whereStatements])
                    ->site('*')
                    ->status(null);

                return [
                    ...$query->all(),
                    ...$query->drafts()->all(),
                ];
            })
            ->flatten()

            // Check if Element has a valid `primaryOwner` or ´owner`
            // https://github.com/internetztube/craft-element-relations/issues/35
            ->filter(function (ElementInterface $element) {
                if (method_exists($element, 'getPrimaryOwner')) {
                    try {
                        $element->getPrimaryOwner();
                    } catch (\Exception $e) {
                        return false;
                    }
                }
                if (method_exists($element, 'getOwner')) {
                    try {
                        $element->getOwner();
                    } catch (\Exception $e) {
                        return false;
                    }
                }
                return true;
            })
            ->all();
    }

    public static function isUsedInSeomaticGlobalSettings(ElementInterface $element): bool
    {
        if (!Craft::$app->plugins->isPluginEnabled('seomatic') || !($element instanceof Asset)) {
            return false;
        }
        $columnSelector = DatabaseService::jsonExtract("metaBundleSettings", ["seoImageIds"]);

        return (new Query())
            ->from([\nystudio107\seomatic\records\MetaBundle::tableName()])
            ->where(['=', $columnSelector, "[\"$element->id\"]"])
            ->collect()
            ->isNotEmpty();
    }
}