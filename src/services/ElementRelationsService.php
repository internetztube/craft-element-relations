<?php

namespace internetztube\elementRelations\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\helpers\Cp;
use internetztube\elementRelations\fields\ElementRelationsField;

class ElementRelationsService
{
    public static function getRelations(ElementInterface $element)
    {
        $relations = self::getRelationsFromElement($element);
        $elements = collect();
        $markup = collect();
        if ($element instanceof Asset) {
            $assetUsageInSeomatic = ElementRelationsService::assetUsageInSEOmatic($element);
            if ($assetUsageInSeomatic['usedGlobally']) {
                $markup->push('Used in SEOmatic Global Settings');
            }
            if (!empty($assetUsageInSeomatic['elements'])) {
                $markup->push('Used in SEOmatic in these Elements (+Drafts):');
                $markup->push(Cp::elementPreviewHtml($assetUsageInSeomatic['elements']));
                $elements = $elements->merge($assetUsageInSeomatic['elements']);
            }

            $assetUsageInProfilePhotos = ElementRelationsService::assetUsageInProfilePhotos($element);
            if (!empty($assetUsageInProfilePhotos)) {
                $markup->push(Cp::elementPreviewHtml($assetUsageInProfilePhotos, 'default', true, false, true));
                $elements = $elements->merge($assetUsageInProfilePhotos);
            }
        }

        if (!empty($relations)) {
            $markup->push(Cp::elementPreviewHtml($relations, 'default'));
            $elements = $elements->merge($relations);
        }

        if ($markup->isEmpty()) {
            $markup->push('<span style="color: #da5a47;">Unused</span>');
        }

        return [
            'elementIds' => $elements->pluck('id')->unique()->all(),
            'markup' => $markup->implode('<br />'),
        ];
    }

    private static function getRelationsFromElement(ElementInterface $sourceElement, bool $anySite = false): array
    {
        $elements = (new Query())->select(['elements.id'])
            ->from(['relations' => Table::RELATIONS])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[relations.sourceId]] = [[elements.id]]')
            ->where(['relations.targetId' => $sourceElement->canonicalId])
            ->column();

        $siteId = $anySite ? '*' : $sourceElement->siteId;

        return collect($elements)->map(function (int $elementId) use ($siteId) {
            /** @var ?Element $relation */
            $relation = self::getElementById($elementId, $siteId);
            if (!$relation) {
                return null;
            }
            return self::getRootElement($relation, $siteId);
        })->filter()->unique(function (ElementInterface $element) {
            return $element->id . $element->siteId;
        })->values()->toArray();
    }

    public static function getElementById(int $elementId, int $siteId): ?Element
    {
        $result = (new Query())->select(['type'])->from(Table::ELEMENTS)->where(['id' => $elementId])->one();
        if (!$result) {
            return null; // relation is broken
        }
        return $result['type']::find()->id($elementId)->anyStatus()->siteId($siteId)->one();
    }

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

    private static function assetUsageInSEOmatic(Element $sourceElement)
    {
        $result = ['usedGlobally' => false, 'elements' => []];
        $isInstalled = Craft::$app->db->tableExists('{{%seomatic_metabundles}}');
        if (!$isInstalled) {
            return false;
        }

        $extractIdFromString = function ($input) {
            if (!$input) {
                return false;
            }
            $result = sscanf($input, '{seomatic.helper.socialTransform(%d, ');
            return (int)collect($result)->first();
        };

        $globalQueryResult = (new Query)->select(['metaGlobalVars', 'metaSiteVars'])
            ->from('{{%seomatic_metabundles}}')
            ->all();

        $result['usedGlobally'] = collect($globalQueryResult)
            ->map(function ($row) {
                return collect($row)->values();
            })
            ->flatten()
            ->map(function ($row) {
                return json_decode($row, true);
            })
            ->map(function ($row) use ($extractIdFromString) {
                if (isset($row['seoImage'])) {
                    return $extractIdFromString($row['seoImage']);
                }
                if (isset($row['identity']['genericImageIds'])) {
                    return $row['identity']['genericImageIds'];
                }
                return null;
            })
            ->flatten()->filter()
            ->map(function ($row) {
                return (int)$row;
            })->unique()
            ->contains($sourceElement->id);

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

        collect($fields)->each(function ($handle) use (&$foundElements, $extractIdFromString, $sourceElement) {
            $fieldHandle = sprintf('field_%s', $handle);
            $rows = (new Query)->select(['elements.canonicalId', 'elements.id', 'siteId', 'title', 'content.' . $fieldHandle])
                ->from(['content' => Table::CONTENT])
                ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[content.elementId]]')
                ->where(['NOT', ['content.' . $fieldHandle => null]])
                ->all();
            collect($rows)->each(function ($row) use (&$foundElements, $extractIdFromString, $fieldHandle, $sourceElement) {
                $data = json_decode($row[$fieldHandle]);
                $id = $extractIdFromString($data->metaGlobalVars->seoImage);
                if ($id !== $sourceElement->id) {
                    return false;
                }
                $foundElements->push(self::getElementById($row['canonicalId'] ?? $row['id'], $row['siteId']));
            });
        });
        $result['elements'] = collect($foundElements)
            ->unique('canonicalId')
            ->toArray();
        return $result;
    }

    private static function assetUsageInProfilePhotos(Element $sourceElement): array
    {
        $users = (new Query())
            ->select(['id'])
            ->from(Table::USERS)
            ->where(['photoId' => $sourceElement->id])
            ->all();

        return collect($users)->map(function (array $user) {
            return Craft::$app->users->getUserById($user['id']);
        })->all();
    }

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
            return ['elementId' => $row['canonicalId'] ?? $row['elementId'], 'siteId' => $row['siteId']];
        })->unique()->values()->all();
    }
}
