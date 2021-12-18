<?php

namespace internetztube\elementRelations\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\ArrayHelper;
use craft\helpers\Cp;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Site;
use craft\services\Sections;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\fields\ElementRelationsField;
use internetztube\elementRelations\models\ElementRelationsModel;
use internetztube\elementRelations\records\ElementRelationsRecord;
use phpDocumentor\Reflection\Types\Void_;
use Throwable;
use yii\base\InvalidConfigException;
use yii\db\ActiveRecord;
use yii\db\StaleObjectException;
use yii\web\NotFoundHttpException;

class ElementRelationsService extends Component
{

    public    $cacheDuration = '1 week';
    protected $settings;

    public function __construct($config = [])
    {
        $this->settings = ElementRelations::$plugin->getSettings();
        parent::__construct($config);
    }

    /**
     * @throws Throwable
     * @throws StaleObjectException
     * @throws NotFoundHttpException
     */
    public static function refreshEntryRelations(Entry $entry): void
    {
        $settings = ElementRelations::$plugin->getSettings();
        if ($settings->useCache) {
            $relationsRecords = self::getRelatedRelationsRecords($entry->id, $entry->siteId);
            $service = new ElementRelationsService();
            foreach ($relationsRecords as $record) {
                $element = Craft::$app->elements->getElementById($record->elementId, null, $record->siteId);
                if (!$element) {
                    self::deleteRelationsRecord($record);
                }
                $service->getRelations($element, $element->id, $element->siteId, 'default', true);
            }
        }
    }

    /**
     * @param int $elementId
     * @param int $siteId
     * @return array|ActiveRecord[]
     */
    public static function getRelatedRelationsRecords(int $elementId, int $siteId): array
    {
        $settings = ElementRelations::$plugin->getSettings();
        if ($settings->useCache) {
            return ElementRelationsRecord::find()
                ->where(['like', 'relations', $elementId])
                ->andWhere(['siteId' => $siteId])
                ->all();
        }
        return [];
    }

    /**
     * @param ElementRelationsRecord|ActiveRecord $record
     * @throws Throwable
     * @throws StaleObjectException
     */
    public static function deleteRelationsRecord(ElementRelationsRecord $record): void
    {
        $settings = ElementRelations::$plugin->getSettings();
        if ($settings->useCache) {
            $record->delete();
        }
    }

    /**
     * Get the datetime used for determining a stale cache
     * @return string
     */
    public function getStaleDateTime():string
    {
        $cacheDuration = $this->getCacheDuration();
        return date('Y-m-d H:i:s', strtotime("$cacheDuration ago"));
    }

    /**
     * @return string Cache Duration from config, settings, or class
     */
    public function getCacheDuration(): string {
        return $this->settings->cacheDuration ?? $this->cacheDuration;
    }

    /**
     * @param Element|ElementInterface $element
     * @param int $elementId
     * @param int $siteId
     * @param string $size
     * @param bool $force Force the record to re-cache
     * @return string|bool
     */
    public function getRelations(Element $element, int $elementId, int $siteId, string $size = 'default', bool $force = false): string
    {
        $staleDateTime = $this->getStaleDateTime();
        $stale = false;

        $cachedRelations = self::getStoredRelations($elementId, $siteId);
        if (!empty($cachedRelations) && $cachedRelations->dateUpdated < $staleDateTime) {
            $stale = true;
        }
        $request = Craft::$app->getRequest();
        $isConsoleRequest = ($request->getIsConsoleRequest());

        if ($force || !$cachedRelations || $stale) {
            $relations = self::getRelationsFromElement($element);
            $result = collect();
            if ($element instanceof Asset) {
                $assetUsageInSeomatic = ElementRelationsService::assetUsageInSEOmatic($element);
                if ($assetUsageInSeomatic['usedGlobally']) {
                    $result->push('Used in SEOmatic Global Settings');
                }
                if (!empty($assetUsageInSeomatic['elements'])) {
                    $result->push('Used in SEOmatic in these Elements (+Drafts):');
                    $result->push(Cp::elementPreviewHtml($assetUsageInSeomatic['elements'], $size));
                }

                $assetUsageInProfilePhotos = ElementRelationsService::assetUsageInProfilePhotos($element);
                if (!empty($assetUsageInProfilePhotos)) {
                    $result->push(Cp::elementPreviewHtml($assetUsageInProfilePhotos, $size, true, false, true));
                }
            }

            if (!empty($relations)) {
                $result->push(Cp::elementPreviewHtml($relations, $size));
            } else {
                $relationsAnySite = self::getRelationsFromElement($element, true);
                if (!empty($relationsAnySite)) {
                    $result->push('Unused in this site, but used in others:');
                    $result->push(Cp::elementPreviewHtml($relationsAnySite, $size));
                }
            }

            if ($result->isEmpty()) {
                $result->push('<span style="color: #da5a47;">Unused</span>');
            }
            $resultHtml = $result->implode('<br />');

            //set it or Update it
            $relationsIds = array_unique(ArrayHelper::getColumn($relations, 'id'));
            self::setStoredRelations($elementId, $siteId, $relationsIds, $resultHtml);
        } else {
            if (!$isConsoleRequest) {
                $resultHtml = $cachedRelations->getResultHtml();
            }
        }
        if (!$isConsoleRequest) {
            return $resultHtml;
        } else {
            return true;
        }
    }

    /**
     * @param int $elementId
     * @param int $siteId
     * @return false|ElementRelationsModel
     */
    public static function getStoredRelations(int $elementId, int $siteId)
    {
        $settings = ElementRelations::$plugin->getSettings();
        if ($settings->useCache) {
            $elementRelationsRecord = self::getStoredRelationsRecord($elementId, $siteId);

            $elementRelationsModel = new ElementRelationsModel();
            if ($elementRelationsRecord) {
                $attributes = $elementRelationsRecord->getAttributes();
                $elementRelationsModel->setAttributes($attributes, false);
                return $elementRelationsModel;
            }
        }
        return false;
    }

    /**
     * @param int $elementId
     * @param int $siteId
     * @return array|ActiveRecord|null
     */
    public static function getStoredRelationsRecord(int $elementId, int $siteId)
    {
        $settings = ElementRelations::$plugin->getSettings();
        if ($settings->useCache) {
            return ElementRelationsRecord::find()
                ->where(['elementId' => $elementId])
                ->andWhere(['siteId' => $siteId])
                ->one();
        }
        return null;
    }

    /**
     * @param Element $sourceElement
     * @param bool $anySite
     * @return array
     */
    public static function getRelationsFromElement(Element $sourceElement, bool $anySite = false): array
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
            if (!$relation) {
                return null;
            }
            return self::getRootElement($relation, $site);
        })->filter()->values()->toArray();
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

    public static function assetUsageInProfilePhotos(Element $sourceElement)
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
    public static function assetUsageInSEOmatic(Element $sourceElement)
    {
        $result = ['usedGlobally' => false, 'elements' => []];
        $isInstalled = Craft::$app->db->tableExists('{{%seomatic_metabundles}}');
        if (!$isInstalled) { return false; }

        $extractIdFromString = function ($input) {
            if (!$input) { return false; }
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
            if (empty($field['columnSuffix'])) { return $field['handle']; }
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
                if ($id !== $sourceElement->id) { return false; }
                $foundElements->push(self::getElementById($row['canonicalId'] ?? $row['id'], $row['siteId']));
            });
        });
        $result['elements'] = collect($foundElements)
            ->unique('canonicalId')
            ->toArray();
        return $result;
    }

    /**
     * Add the relation to the db. Note dateUpdated is added here because
     * otherwise if nothing changes, the record is not updated, and we use that for cache staleness
     * @param int $elementId
     * @param int $siteId
     * @param array $relations
     * @param string $resultHtml
     */
    public static function setStoredRelations(int $elementId, int $siteId, array $relations, string $resultHtml)
    {
        $settings = ElementRelations::$plugin->getSettings();
        if ($settings->useCache) {
            if (!$elementRelationsRecord = self::getStoredRelationsRecord($elementId, $siteId)) {
                $elementRelationsRecord = new ElementRelationsRecord();
            }
            $elementRelationsRecord->setAttribute('elementId', $elementId);
            $elementRelationsRecord->setAttribute('siteId', $siteId);
            $elementRelationsRecord->setAttribute('relations', implode(',', $relations));
            $elementRelationsRecord->setAttribute('resultHtml', $resultHtml);
            $elementRelationsRecord->setAttribute('dateUpdated', date('Y-m-d H:i:s'));
            $elementRelationsRecord->save();
        }
    }

    /**
     * @param Entry $entry
     * @throws Throwable
     * @throws StaleObjectException
     */
    public static function clearRelatedCaches(Entry $entry)
    {
        $settings = ElementRelations::$plugin->getSettings();
        if ($settings->useCache) {
            $relationsRecords = self::getRelatedRelationsRecords($entry->id, $entry->siteId);
            foreach ($relationsRecords as $record) {
                self::deleteRelationsRecord($record);
            }
        }
    }

    /**
     * @param bool $staleOnly
     * @param string $siteId // not implemented
     * @return array|ActiveRecord[]
     */
    public function getAllRelations(bool $staleOnly = true, string $siteId = '*'): array
    {
        $relationsQuery = ElementRelationsRecord::find();
        if ($staleOnly) {
            $staleDateTime = $this->getStaleDateTime();
            $relationsQuery->where(["<",'dateUpdated', $staleDateTime]);
        }
        return $relationsQuery->all();
    }

    /**
     * @return array
     * @throws InvalidConfigException
     */
    public function getRelatedElementEntryTypes(): array
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
}
