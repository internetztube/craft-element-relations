<?php

namespace internetztube\elementRelations\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use Illuminate\Support\Collection;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;
use internetztube\elementRelations\services\extractors\SpecialExtractorSeomaticGlobalService;
use internetztube\elementRelations\services\RelationsService;
use internetztube\elementRelations\services\SpecialSeomaticGlobalService;

class ElementRelationsField extends Field implements PreviewableFieldInterface
{
    public static function supportedTranslationMethods(): array
    {
        return [self::TRANSLATION_METHOD_NONE];
    }

    public static function displayName(): string
    {
        return Craft::t('element-relations', 'Element Relations');
    }

    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        return Craft::$app->getView()->renderTemplate('element-relations/_components/fields/relations_preview', [
            'relations' => $this->getRelations($element),
            'seomaticGlobal' => SpecialExtractorSeomaticGlobalService::isInUse($element),
        ]);
    }

    public function getInputHtml(mixed $value, ElementInterface $element = null): string
    {
        return Craft::$app->getView()->renderTemplate('element-relations/_components/fields/relations', [
            'relations' => $this->getRelations($element),
            'seomaticGlobal' => SpecialExtractorSeomaticGlobalService::isInUse($element),
        ]);
    }

    /**
     * @return ElementInterface[]
     */
    private function getRelations(ElementInterface $element): array
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
