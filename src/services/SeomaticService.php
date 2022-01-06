<?php

namespace internetztube\elementRelations\services;

use Craft;
use craft\db\Query;
use craft\db\Table;

class SeomaticService
{
    public const IDENTIFIER_SEOMATIC_GLOBAL = 'seomatic-global';
    public const IDENTIFIER_SEOMATIC_LOCAL_START = 'seomatic-local-start-';
    public const IDENTIFIER_SEOMATIC_LOCAL_END = 'seomatic-local-end-';

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
        $globalQueryResult = (new Query())->select(['sourceSiteId', 'metaGlobalVars', 'metaSiteVars'])
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
     * Is the SEOmatic Plugin installed and enabled?
     * @return bool
     */
    public static function isSeomaticEnabled(): bool
    {
        return Craft::$app->plugins->isPluginEnabled('seomatic');
    }
}