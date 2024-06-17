<?php

namespace internetztube\elementRelations\services;

use craft\base\ElementInterface;
use craft\base\Field;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
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
            ->leftJoin(['elements_sites' => Table::ELEMENTS_SITES],"[[elements_sites.elementId]] = [[elements.id]]")
            ->where([
                'and',
                ['=', 'elementrelations_cache.targetElementId', $element->id],
                ['=', 'elementrelations_cache.targetSiteId', $element->siteId],
            ])
            ->collect()
            ->groupBy('type')
            ->map(function (Collection $items, string $elementType) {
                $whereStatements = $items->map(fn($value) => [
                    "elements.id" => $value["elementId"],
                    "elements_sites.siteId" => $value["siteId"]
                ]);

                /** @var ElementQuery $query */
                $query = $elementType::find()
                    ->where(["or", ...$whereStatements])
                    ->site('*')
                    ->status(null);

                return [
                    ...$query->all(),
                    ...$query->drafts()->all(),
                ];
            })
            ->flatten()
            ->all();
    }
}