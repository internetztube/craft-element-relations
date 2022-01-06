<?php

namespace internetztube\elementRelations\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\fields\Matrix as MatrixField;
use internetztube\elementRelations\fields\ElementRelationsField;
use verbb\supertable\fields\SuperTableField;

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
                $relations->push(ProfilePhotoService::IDENTIFIER_PROFILE_PHOTO_START);
                $relations = $relations->merge($assetUsageInProfilePhotos);
                $relations->push(ProfilePhotoService::IDENTIFIER_PROFILE_PHOTO_END);
            }
        }

        $redactorRelations = collect();
        if (RedactorService::isRedactorEnabled()) {
            $redactorRelations = collect(RedactorService::getRedactorRelations($elementId));
        }
        $linkItRelations = collect();
        if (LinkItService::isLinkItEnabled()) {
            $linkItRelations = collect(LinkItService::getLinkItRelations($elementId));
        }

        $relations = collect(Craft::$app->sites->allSiteIds)
            ->values()
            ->map(function (int $siteId) use ($elementId, $redactorRelations, $linkItRelations) {
                $redactorRelationsForSite = $redactorRelations->where('siteId', $siteId)->pluck('elementId');
                $linkItRelationsForSite = $linkItRelations->where('siteId', $siteId)->pluck('elementId');

                $elementIds = collect(self::getElementRelationsFromElement($elementId, $siteId))->pluck('elementId')
                    ->merge($redactorRelationsForSite)
                    ->merge($linkItRelationsForSite);
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

        if (LinkItService::isLinkItEnabled()) {
            $linkItRelations = LinkItService::getLinkItRelationsUsedInElement($elementId);
            $result = $result->merge($linkItRelations);
        }

        return $result->all();
    }

    /**
     * Is the SuperTable Plugin installed and enabled?
     * @return bool
     */
    private static function isSuperTableEnabled(): bool
    {
        return Craft::$app->plugins->isPluginEnabled('super-table');
    }

    /**
     * @param string $fieldType
     * @param string $likeStatement
     * @return array
     *
     * <code>
     * [
     *   ['elementId' => int, 'siteId' => int]
     * ]
     * </code>
     */
    public static  function getFilledContentRowsByFieldType(string $fieldType, string $likeStatement): array
    {
        $mainQuery = (new Query())
            ->from(['elements' => Table::ELEMENTS])
            ->select(['elements.id', 'elements.type']);

        // content table
        $fields = (new Query())->select(['id'])
            ->from(Table::FIELDS)
            ->where(['type' => $fieldType])
            ->andWhere(['context' => 'global'])
            ->column();
        $fieldHandles = collect($fields)->map(function (int $fieldId) use ($likeStatement, $mainQuery) {
            $field = Craft::$app->getFields()->getFieldById($fieldId);
            $fieldHandle = 'content.field_' . $field->columnPrefix . $field->handle;
            if ($field->columnSuffix) {
                $fieldHandle .= '_' . $field->columnSuffix;
            }
            $mainQuery->addSelect($fieldHandle);
            $mainQuery->orWhere(['LIKE', $fieldHandle, $likeStatement, false]);
            return $fieldHandle;
        });
        if ($fieldHandles->isNotEmpty()) {
            $mainQuery->leftJoin(['content' => Table::CONTENT], '[[content.elementId]] = [[elements.id]]');
            $mainQuery->addSelect('content.siteId as content__siteId');
        }

        $fieldsWithExternalContentTables = collect();
        $matrixFields = (new Query())->select(['id'])->from(Table::FIELDS)->where(['type' => MatrixField::class])->column();
        $fieldsWithExternalContentTables = $fieldsWithExternalContentTables->merge($matrixFields);
        if (self::isSuperTableEnabled()) {
            $superTableFields = (new Query())->select(['id'])->from(Table::FIELDS)->where(['type' => SuperTableField::class])->column();
            $fieldsWithExternalContentTables = $fieldsWithExternalContentTables->merge($superTableFields);
        }

        $fieldsWithExternalContentTables->each(function (int $fieldId, int $index) use ($mainQuery, $likeStatement, $fieldType) {
            $alias = sprintf('alias_%s', $index);
            /** @var MatrixField|SuperTableField $field */
            $field = Craft::$app->getFields()->getFieldById($fieldId);
            $fieldsOfType = collect($field->getBlockTypeFields())->filter(function (FieldInterface $field) use ($fieldType) {
                return $field instanceof $fieldType;
            });
            if ($fieldsOfType->isEmpty()) {
                return;
            }
            $fieldsOfType->each(function ($field) use ($alias, $mainQuery, $likeStatement) {
                $fieldHandle = $alias . '.' . $field->columnPrefix . $field->handle;
                if ($field->columnSuffix) {
                    $fieldHandle = $alias . '.' . $field->columnPrefix . $field->handle . '_' . $field->columnSuffix;
                }
                $mainQuery->addSelect([$fieldHandle, $alias . '.siteId as ' . $alias . '__siteId']);
                $mainQuery->orWhere(['LIKE', $fieldHandle, $likeStatement, false]);
            });
            $aliasFieldName = sprintf('%s.elementId', $alias);
            $mainQuery->leftJoin([$alias => $field->contentTable], '[[' . $aliasFieldName . ']] = [[elements.id]]');
        });

        return collect($mainQuery->all())->map(function (array $row) {
            $siteIdKey = collect($row)->keys()->filter(function (string $fieldHandle) {
                return strstr($fieldHandle, '__siteId');
            })->first();
            $siteId = $row[$siteIdKey];
            $element = ElementRelationsService::getElementById($row['id'], $row[$siteIdKey]);
            if (!$element) {
                return null;
            }
            $rootElement = ElementRelationsService::getRootElement($element, $siteId);
            if (!$rootElement) {
                return null;
            }
            return ['elementId' => $rootElement->id, 'siteId' => $rootElement->siteId];
        })->filter()->all();
    }
}
