<?php

namespace internetztube\elementRelations\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use internetztube\elementRelations\fields\ElementRelationsField;

class ElementRelationsService
{
    public const IDENTIFIER_ELEMENTS_START = 'elements-start-';
    public const IDENTIFIER_ELEMENTS_END = 'elements-end-';

    public const IDENTIFIER_DELIMITER = '|';

    /**
     * Get stringified element relations of an element. (uncached)
     * @param int $elementId
     * @return string
     */
    public static function getElementRelations(int $elementId): string
    {
        $elementType = self::getElementTypeById($elementId);
        $relations = collect();

        if ($elementType === Asset::class) {
            if (SeomaticService::isSeomaticEnabled()) {
                $isAssetUsedInSeomaticGlobalSettings = collect(SeomaticService::getGlobalSeomaticAssets())
                    ->where('elementId', $elementId)
                    ->isNotEmpty();
                if ($isAssetUsedInSeomaticGlobalSettings) {
                    $relations->push(SeomaticService::IDENTIFIER_SEOMATIC_GLOBAL);
                }

                collect(SeomaticService::getAssetLocalSeomaticRelations($elementId))->groupBy('siteId')
                    ->each(function ($simpleElements, $siteId) use (&$relations) {
                        $relations->push(SeomaticService::IDENTIFIER_SEOMATIC_LOCAL_START . $siteId);
                        $relations = $relations->merge(collect($simpleElements)->pluck('elementId'));
                        $relations->push(SeomaticService::IDENTIFIER_SEOMATIC_LOCAL_END . $siteId);
                    });
            }

            $assetUsageInProfilePhotos = ProfilePhotoService::getAssetUsageInProfilePhotos($elementId);
            if (!empty($assetUsageInProfilePhotos)) {
                $relations->push(ProfilePhotoService::IDENTIFIER_PROFILE_PICTURE_START);
                $relations = $relations->merge($assetUsageInProfilePhotos);
                $relations->push(ProfilePhotoService::IDENTIFIER_PROFILE_PICTURE_END);
            }
        }

        $redactorRelations = collect();
        if (RedactorService::isRedactorEnabled()) {
            $redactorRelations = collect(RedactorService::getRedactorRelations($elementId));
        }

        $relations = collect(Craft::$app->sites->allSiteIds)
            ->values()
            ->map(function (int $siteId) use ($elementId, $redactorRelations) {
                $redactorRelationsForSite = $redactorRelations->where('siteId', $siteId)->pluck('elementId');
                $elementIds = collect(self::getElementRelationsFromElement($elementId, $siteId))->pluck('elementId')
                    ->merge($redactorRelationsForSite);
                if ($elementIds->isEmpty()) {
                    return null;
                }
                return collect()
                    ->push(self::IDENTIFIER_ELEMENTS_START . $siteId)
                    ->merge($elementIds)
                    ->push(self::IDENTIFIER_ELEMENTS_END . $siteId);
            })
            ->filter()
            ->flatten()
            ->merge($relations);

        return collect()->push('')
            ->merge($relations)
            ->push('')
            ->implode(self::IDENTIFIER_DELIMITER);
    }

    /**
     * Get the element type via the element id.
     * @param int $elementId
     * @return mixed|null
     */
    private static function getElementTypeById(int $elementId): ?string
    {
        $elementType = (new Query())->select(['type'])
            ->from(Table::ELEMENTS)
            ->where(['id' => $elementId])
            ->one();
        if (!$elementType) {
            return null;
        }
        return $elementType['type'];
    }

    /**
     * Get all relations of an element that are stored in the `relations` table.
     * @param int $elementId
     * @param $siteId
     * @return array
     *
     * <code>
     * [
     *   ['elementId' => int, 'siteId' => int]
     * ]
     * </code>
     */
    private static function getElementRelationsFromElement(int $elementId, $siteId): array
    {
        $elements = (new Query())->select(['elements.id'])
            ->from(['relations' => Table::RELATIONS])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[relations.sourceId]] = [[elements.id]]')
            ->where(['relations.targetId' => $elementId])
            ->column();

        return collect($elements)->map(function (int $elementId) use ($siteId) {
            /** @var ?Element $element */
            $element = self::getElementById($elementId, $siteId);
            if (!$element) {
                return null;
            }
            return self::getRootElement($element, $siteId);
        })
            ->filter()
            ->unique(function (ElementInterface $element) {
                return sprintf('%s-%s', $element->id, $element->siteId);
            })
            ->values()
            ->map(function (ElementInterface $element) {
                return ['elementId' => $element->id, 'siteId' => $element->siteId];
            })
            ->all();
    }

    /**
     * Get an element by id with the corresponding Element Query Builder.
     * @param int $elementId
     * @param null $siteId
     * @return ElementInterface|null
     */
    public static function getElementById(int $elementId, $siteId = null): ?ElementInterface
    {
        $elementType = self::getElementTypeById($elementId);
        if (!$elementType) {
            return null;
        } // relation is broken
        return $elementType::find()->id($elementId)->anyStatus()->siteId($siteId)->one();
    }

    /**
     * Get root element of an element. Mainly used for Matrix, SuperTable, and Neo blocks.
     * @param ElementInterface $element
     * @param $siteId
     * @return ElementInterface|null
     */
    public static function getRootElement(ElementInterface $element, $siteId): ?ElementInterface
    {
        if (!isset($element->ownerId) || !$element->ownerId) {
            return $element;
        }
        $sourceElement = self::getElementById($element->ownerId, $siteId);
        if (!$sourceElement) {
            return null;
        }
        return self::getRootElement($sourceElement, $siteId);
    }

    /**
     * Get all elements that have an "Element Relations"-Field in their FieldLayout.
     * @return int[]
     */
    public static function getElementsWithElementRelationsField(): array
    {
        $fieldLayoutIds = (new Query())->select(['fieldlayouts.id'])
            ->from(['fieldlayouts' => Table::FIELDLAYOUTS])
            ->innerJoin(['fieldlayoutfields' => Table::FIELDLAYOUTFIELDS], '[[fieldlayouts.id]] = [[fieldlayoutfields.layoutId]]')
            ->innerJoin(['fields' => Table::FIELDS], '[[fieldlayoutfields.fieldId]] = [[fields.id]]')
            ->where(['fields.type' => ElementRelationsField::class])
            ->column();

        $rows = (new Query())->select(['elements_sites.elementId', 'elements_sites.siteId', 'elements.canonicalId'])
            ->from(['elements' => Table::ELEMENTS])
            ->innerJoin(['elements_sites' => Table::ELEMENTS_SITES], '[[elements.id]] = [[elements_sites.elementId]]')
            ->where(['in', 'fieldLayoutId', $fieldLayoutIds])
            ->all();

        return collect($rows)->map(function (array $row) {
            return (int)($row['canonicalId'] ?? $row['elementId']);
        })->unique()->values()->all();
    }

    public static function getRelationsUsedInElement(int $elementId): array
    {
        $elementIds = (new Query())
            ->select(['relations.targetId'])
            ->from(['relations' => Table::RELATIONS])
            ->where(['relations.sourceId' => $elementId])
            ->column();
        $result = collect($elementIds)->map(function ($elementId) {
            return (int)$elementId;
        });

        if (RedactorService::isRedactorEnabled()) {
            $redactorRelations = RedactorService::getRedactorRelationsUsedInElement($elementId);
            $result = $result->merge($redactorRelations);
        }

        return $result->all();
    }
}
