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
    public const IDENTIFIER_SEOMATIC_GLOBAL = 'seomatic-global';
    public const IDENTIFIER_SEOMATIC_LOCAL_START = 'seomatic-local-start-';
    public const IDENTIFIER_SEOMATIC_LOCAL_END = 'seomatic-local-end-';

    public const IDENTIFIER_PROFILE_PICTURE_START = 'profile-picture-start';
    public const IDENTIFIER_PROFILE_PICTURE_END = 'profile-picture-end';

    public const IDENTIFIER_ELEMENTS_START = 'elements-start-';
    public const IDENTIFIER_ELEMENTS_END = 'elements-end-';

    public const IDENTIFIER_DELIMITER = '|';

    /**
     * Get element relations of an element. (uncached)
     * @param ElementInterface $element
     * @return array
     */
    public static function getElementRelations(int $elementId): string
    {
        $elementType = self::getElementTypeById($elementId);
        $relations = collect();

        if ($elementType === Asset::class) {
            $assetUsageInSeomatic = self::getAssetUsageInSeomatic($elementId);
            if ($assetUsageInSeomatic['usedGlobally']) {
                $relations->push(self::IDENTIFIER_SEOMATIC_GLOBAL);
            }
            if (!empty($assetUsageInSeomatic['localRelations'])) {
                collect($assetUsageInSeomatic['localRelations'])
                    ->groupBy('siteId')
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
        $elementType = (new Query())->select(['type'])->from(Table::ELEMENTS)->where(['id' => $elementId])->one();
        if (!$elementType) {
            return null;
        }
        return $elementType['type'];
    }

    /**
     * Check if an asset is used in SEOmatic entry specific and global settings.
     * @param Asset $sourceElement
     * @return array|false
     */
    private static function getAssetUsageInSeomatic(int $assetId)
    {
        $result = ['usedGlobally' => false, 'elements' => []];
        if (!self::isSEOmaticInstalled()) {
            return null;
        }

        $result['usedGlobally'] = collect(self::getGlobalSeomaticAssets())
            ->where('elementId', $assetId)
            ->isNotEmpty();

        $result['localRelations'] = self::getAssetLocalSeomaticRelations($assetId);
        return $result;
    }

    public static function isSEOmaticInstalled(): bool
    {
        return Craft::$app->db->tableExists('{{%seomatic_metabundles}}');
    }

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
                            $result->push(['elementId' => (int)$elementId, 'siteId' => $siteId]);
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
     * Checks if an Asset is used as an profile picture.
     * @param Element $sourceElement
     * @return array
     */
    private static function getAssetUsageInProfilePhotos(int $assetId): array
    {
        $users = (new Query())
            ->select(['id'])
            ->from(Table::USERS)
            ->where(['photoId' => $assetId])
            ->all();

        return collect($users)->map(function (array $user) {
            return Craft::$app->users->getUserById($user['id']);
        })->pluck('id')->all();
    }

    /**
     * Get all relations of an element that are stored in the `relations` table.
     * @param ElementInterface $sourceElement
     * @param bool $anySite
     * @return array
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
     * @param int $elementId ^
     * @param int $siteId
     * @return Element|null
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
     * @param Element $element
     * @param $siteId
     * @return Element|null
     */
    private static function getRootElement(Element $element, $siteId): ?Element
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
