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
use craft\redactor\Field as RedactorField;

class ElementRelationsService
{
    public const IDENTIFIER_SEOMATIC_GLOBAL = 'seomatic-global';
    public const IDENTIFIER_SEOMATIC_LOCAL_START = 'seomatic-local-start-';
    public const IDENTIFIER_SEOMATIC_LOCAL_END = 'seomatic-local-end-';

    public const IDENTIFIER_PROFILE_PICTURE_START = 'profile-picture-start';
    public const IDENTIFIER_PROFILE_PICTURE_END = 'profile-picture-end';

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

        self::getElementsFromRedactor($elementId);

        if ($elementType === Asset::class) {
            if (self::isSeomaticEnabled()) {
                $isAssetUsedInSeomaticGlobalSettings = collect(self::getGlobalSeomaticAssets())
                    ->where('elementId', $elementId)
                    ->isNotEmpty();
                if ($isAssetUsedInSeomaticGlobalSettings) {
                    $relations->push(self::IDENTIFIER_SEOMATIC_GLOBAL);
                }

                collect(self::getAssetLocalSeomaticRelations($elementId))->groupBy('siteId')
                    ->each(function ($simpleElements, $siteId) use (&$relations) {
                        $relations->push(self::IDENTIFIER_SEOMATIC_LOCAL_START . $siteId);
                        $relations = $relations->merge(collect($simpleElements)->pluck('elementId'));
                        $relations->push(self::IDENTIFIER_SEOMATIC_LOCAL_END . $siteId);
                    });
            }

            $assetUsageInProfilePhotos = self::getAssetUsageInProfilePhotos($elementId);
            if (!empty($assetUsageInProfilePhotos)) {
                $relations->push(self::IDENTIFIER_PROFILE_PICTURE_START);
                $relations = $relations->merge($assetUsageInProfilePhotos);
                $relations->push(self::IDENTIFIER_PROFILE_PICTURE_END);
            }
        }

        $relations = collect(Craft::$app->sites->allSiteIds)
            ->values()
            ->map(function (int $siteId) use ($elementId) {
                $elementIds = collect(self::getElementRelationsFromElement($elementId, $siteId))->pluck('elementId');
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

    private static function getElementTypeById(int $elementId)
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

    private static function getElementsFromRedactor(int $elementId)
    {
        $mainQuery = (new Query())->from(['elements' => Table::ELEMENTS])
            ->leftJoin(['content' => Table::CONTENT], '[[content.elementId]] = [[elements.id]]');
        $mainQuerySelect = collect(['elements.id', 'elements.type']);
        $mainQueryWhere = [];

        $matrixFields = (new Query())->select(['id'])->from(Table::FIELDS)->where(['type' => MatrixField::class])->column();
        $superTableFields = (new Query())->select(['id'])->from(Table::FIELDS)->where(['type' => SuperTableField::class])->column();

        collect()->merge($superTableFields)->merge($matrixFields)
            ->each(function (int $fieldId, int $index) use ($mainQuery, &$mainQuerySelect, &$mainQueryWhere, $elementId) {
                $alias = sprintf('alias_%s', $index);
                /** @var MatrixField|SuperTableField $field */
                $field = Craft::$app->getFields()->getFieldById($fieldId);
                $redactorFields = collect($field->getBlockTypeFields())->filter(function (FieldInterface $field) {
                    return $field instanceof RedactorField;
                });
                if ($redactorFields->isEmpty()) { return; }
                $redactorFields->each(function(RedactorField $field) use (&$mainQuerySelect, &$mainQueryWhere, $alias, $mainQuery) {
                    $fieldHandle = $alias . '.' .$field->columnPrefix . $field->handle;
                    if ($field->columnSuffix) {
                        $fieldHandle = $alias . '.' .$field->columnPrefix . $field->handle . '_' . $field->columnSuffix;
                    }
                    $mainQuerySelect->push($fieldHandle);
                    $mainQueryWhere[$fieldHandle] = null;
                });
                $aliasFieldName = sprintf('%s.elementId', $alias);
                $mainQuery->leftJoin([$alias => $field->contentTable], '[[' . $aliasFieldName . ']] = [[elements.id]]');
            });

        $mainQuery->select($mainQuerySelect->all());
        $mainQuery->orWhere(['NOT', $mainQueryWhere]);

        echo($mainQuery->getRawSql());
        dd();
    }

    /**
     * Is SEOmatic installed and enabled?
     * @return bool
     */
    public static function isSeomaticEnabled(): bool
    {
        return Craft::$app->plugins->isPluginEnabled('seomatic');
    }

    /**
     * All assets that are used within SEOmatic's global settings.
     * @return array
     *
     * <code>
     * [
     *   ['elementId' => int, 'siteId' => int]
     * ]
     * </code>
     */
    public static function getGlobalSeomaticAssets(): array
    {
        $extractIdFromString = function ($input) {
            if (!$input) {
                return false;
            }
            $input = trim(trim($input, '{'));
            $result = sscanf($input, 'seomatic.helper.socialTransform(%d, ');
            return (int)collect($result)->first();
        };

        $result = collect();
        $globalQueryResult = (new Query)->select(['sourceSiteId', 'metaGlobalVars', 'metaSiteVars'])
            ->from('{{%seomatic_metabundles}}')
            ->all();
        collect($globalQueryResult)
            ->each(function ($row) use ($extractIdFromString, $result) {
                $siteId = $row['sourceSiteId'];
                collect([$row['metaGlobalVars'], $row['metaSiteVars']])->map(function ($row) {
                    return json_decode($row, true);
                })->map(function ($row) use ($extractIdFromString, $result, $siteId) {
                    if (isset($row['seoImage'])) {
                        $elementId = $extractIdFromString($row['seoImage']);
                        if ($elementId) {
                            $result->push(['elementId' => $elementId, 'siteId' => $siteId]);
                        }
                    }
                    if (isset($row['identity']['genericImageIds'])) {
                        $genericImageIds = collect($row['identity']['genericImageIds'])->each(function ($elementId) use ($siteId, $result) {
                            if (!$elementId) {
                                return;
                            }
                            $result->push(['elementId' => (int)$elementId, 'siteId' => (int)$siteId]);
                        });
                        $result->merge($genericImageIds);
                    }
                    return $result->all();
                });
            });

        return $result
            ->unique(function ($row) {
                return sprintf('%s-%s', $row['elementId'], $row['siteId']);
            })
            ->all();
    }

    /**
     * Get all elements where a certain asset is used in SEOmatic's local settings.
     * @return array
     *
     * <code>
     * [
     *   ['elementId' => int, 'siteId' => int]
     * ]
     * </code>
     */
    public static function getAssetLocalSeomaticRelations(int $assetId): array
    {
        $extractIdFromString = function ($input) {
            if (!$input) {
                return false;
            }
            $input = trim(trim($input, '{'));
            $result = sscanf($input, 'seomatic.helper.socialTransform(%d, ');
            return (int)collect($result)->first();
        };

        $fields = (new Query)->select(['handle', 'columnSuffix'])
            ->from(Table::FIELDS)
            ->where(['=', 'type', 'nystudio107\seomatic\fields\SeoSettings'])
            ->all();
        $fields = collect($fields)->map(function ($field) {
            if (empty($field['columnSuffix'])) {
                return $field['handle'];
            }
            return sprintf('%s_%s', $field['handle'], $field['columnSuffix']);
        })->toArray();

        $foundElements = collect();

        collect($fields)->each(function ($handle) use (&$foundElements, $extractIdFromString, $assetId) {
            $fieldHandle = sprintf('field_%s', $handle);
            $rows = (new Query)->select(['elements.canonicalId', 'elements.id', 'siteId', 'title', 'content.' . $fieldHandle])
                ->from(['content' => Table::CONTENT])
                ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[content.elementId]]')
                ->where(['NOT', ['content.' . $fieldHandle => null]])
                ->all();

            collect($rows)->each(function ($row) use (&$foundElements, $extractIdFromString, $fieldHandle, $assetId) {
                $data = json_decode($row[$fieldHandle]);
                $foundAssetId = $extractIdFromString($data->metaGlobalVars->seoImage);
                if ($foundAssetId !== $assetId) {
                    return;
                }
                $foundElements->push(['elementId' => $row['canonicalId'] ?? $row['id'], 'siteId' => $row['siteId']]);
            });
        });
        return collect($foundElements)
            ->unique(function ($row) {
                return sprintf('%s-%s', $row['elementId'], $row['siteId']);
            })
            ->values()->all();
    }

    /**
     * Checks where an Asset is used as an profile picture.
     * @param int $assetId
     * @return int[]
     */
    private static function getAssetUsageInProfilePhotos(int $assetId): array
    {
        return (new Query())
            ->select('id')
            ->from(Table::USERS)
            ->where(['photoId' => $assetId])
            ->column();
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
    private static function getRootElement(ElementInterface $element, $siteId): ?ElementInterface
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
}
