<?php

namespace internetztube\elementRelations\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\User;
use craft\helpers\Cp;
use internetztube\elementRelations\fields\ElementRelationsField;
use yii\base\InvalidConfigException;

class ElementRelationsService
{
    public static function getRelations (ElementInterface $element, string $size = 'default')
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
                $markup->push(Cp::elementPreviewHtml($assetUsageInSeomatic['elements'], $size));
                $elements = $elements->merge($assetUsageInSeomatic['elements']);
            }

            $assetUsageInProfilePhotos = ElementRelationsService::assetUsageInProfilePhotos($element);
            if (!empty($assetUsageInProfilePhotos)) {
                $markup->push(Cp::elementPreviewHtml($assetUsageInProfilePhotos, $size, true, false, true));
                $elements = $elements->merge($assetUsageInProfilePhotos);
            }
        }

        if (!empty($relations)) {
            $markup->push(Cp::elementPreviewHtml($relations, $size));
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

    /**
     * @return array
     * @throws InvalidConfigException
     */
    public static function getRelatedElementEntryTypes(): array
    {
        $relatedEntryTypes = [];
        $sections = Craft::$app->getSections();
        $entryTypes = $sections->getAllEntryTypes();

        foreach ($entryTypes as $entryType) {
            $fieldLayout = $entryType->getFieldLayout();
            // Loop through the fields in the layout to see if there is an ElementRelations field
            if ($fieldLayout) {
                $fields = $fieldLayout->getFields();
                foreach ($fields as $field) {
                    if ($field instanceof ElementRelationsField) {
                        $relatedEntryTypes[$entryType->id] = $entryType;
                    }
                }
            }
        }
        return $relatedEntryTypes;
    }

    /**
     * @param Element $sourceElement
     * @param bool $anySite
     * @return array
     */
    public static function getRelationsFromElement(ElementInterface $sourceElement, bool $anySite = false): array
    {
        $elements = (new Query())->select(['elements.id'])
            ->from(['relations' => Table::RELATIONS])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[relations.sourceId]] = [[elements.id]]')
            ->where(['relations.targetId' => $sourceElement->canonicalId])
            ->column();

        $site = $anySite ? '*' : $sourceElement->site;

        return collect($elements)->map(function (int $elementId) use ($site) {
            /** @var ?Element $relation */
            $relation = self::getElementById($elementId, $site);
            if (!$relation) { return null; }
            return self::getRootElement($relation, $site);
        })->filter()->unique(function (ElementInterface $element) {
            return $element->id . $element->siteId;
        })->values()->toArray();
    }

    /**
     * @param int $elementId
     * @param $site
     * @return Element|null
     */
    public static function getElementById(int $elementId, $site): ?Element
    {
        if (is_numeric($site)) {
            $site = Craft::$app->sites->getSiteById($site);
        }
        $result = (new Query())->select(['type'])->from(Table::ELEMENTS)->where(['id' => $elementId])->one();
        if (!$result) {
            return null;
        } // relation is broken
        return $result['type']::find()->id($elementId)->anyStatus()->site($site)->one();
    }

    /**
     * @param Element $element
     * @param $site
     * @return Element|null
     */
    private static function getRootElement(Element $element, $site): ?Element
    {
        if (!isset($element->ownerId) || !$element->ownerId) {
            return $element;
        }
        $sourceElement = self::getElementById($element->ownerId, $site);
        if (!$sourceElement) {
            return null;
        }
        return self::getRootElement($sourceElement, $site);
    }

    /**
     * @param Element $sourceElement
     * @return User[]
     */
    private static function assetUsageInProfilePhotos(Element $sourceElement): array
    {
        $users = (new Query())
            ->select(['id'])
            ->from(Table::USERS)
            ->where(['photoId' => $sourceElement->id])
            ->all();

        return collect($users)->map(function (array $user) {
            return \Craft::$app->users->getUserById($user['id']);
        })->all();
    }

    /**
     * @param Element $sourceElement
     * @return array|false
     */
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
}
