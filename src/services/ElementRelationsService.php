<?php

namespace internetztube\elementRelations\services;

use craft\base\Component;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\ArrayHelper;
use craft\helpers\Cp;
use craft\models\Site;
use internetztube\elementRelations\models\ElementRelationsModel;
use internetztube\elementRelations\records\ElementRelationsRecord;
use yii\web\NotFoundHttpException;

class ElementRelationsService extends Component
{

    public $cacheDuration = '2 days';

    /**
     * @param Element|ElementInterface $element
     * @param int $elementId
     * @param int $siteId
     * @param string $size
     * @param bool $force  Force the record to re-cache
     * @return string
     */
    public function getRelations(Element $element, int $elementId, int $siteId, string $size = 'default', bool $force = false ): string
    {
        $staleCache = date('Y-m-d H:i:s',strtotime("$this->cacheDuration ago"));
        $stale = false;

        $cachedRelations = self::getStoredRelations($elementId, $siteId);
        if (!empty($cachedRelations) && $cachedRelations->dateUpdated < $staleCache) {
            $stale = true;
        }

        // @todo verify it's not out of date
        if ($force || !$cachedRelations || $stale) {
            $relations = self::getRelationsFromElement($element);
            $isUsedInSEOmatic = self::isUsedInSEOmatic($element);

            $result = collect();

            if ($isUsedInSEOmatic['usedGlobally']) {
                $result->push('Used in SEOmatic Global Settings');
            }
            if (!empty($isUsedInSEOmatic['elements'])) {
                $result->push('Used in SEOmatic in these Elements (+Drafts):');
                $result->push(Cp::elementPreviewHtml($isUsedInSEOmatic['elements'], $size));
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

            if ($result->isEmpty()) { $result->push('<span style="color: #da5a47;">Unused</span>'); }
            $resultHtml = $result->implode('<br />');

            //set it or Update it
            $relationsIds = array_unique(ArrayHelper::getColumn($relations, 'id'));
            self::setStoredRelations($elementId, $siteId, $relationsIds, $resultHtml);
        } else {
            $resultHtml = $cachedRelations->getResultHtml();
        }
        return $resultHtml;
    }

    /**
     * @throws \Throwable
     * @throws \yii\db\StaleObjectException
     * @throws NotFoundHttpException
     */
    public static function refreshEntryRelations(Entry $entry) : void {
        $relationsRecords = self::getRelatedRelationsRecords($entry->id, $entry->siteId);
        if (!$element) throw new NotFoundHttpException;
        $service = new ElementRelationsService();
        foreach ($relationsRecords as $record) {
            $element = \Craft::$app->elements->getElementById($record->elementId, null, $record->siteId);
            if (!$element) {
                self::deleteRelationsRecord($record);
            }
            $service->getRelations($element, $elementId, $siteId, 'default', true);
        }
    }

    /**
     * @param Element $sourceElement
     * @param int $siteId
     * @return false|ElementRelationsModel
     */
    public static function getStoredRelations(int $elementId, int $siteId) {
        $elementRelationsRecord = self::getStoredRelationsRecord($elementId, $siteId);

        $elementRelationsModel = new ElementRelationsModel();
        if ($elementRelationsRecord) {
            $attributes = $elementRelationsRecord->getAttributes();
            $elementRelationsModel->setAttributes($attributes, false);
            return $elementRelationsModel;
        }
        return false;
    }

    /**
     * @param int $elementId
     * @param int $siteId
     * @return array|\yii\db\ActiveRecord|null
     */
    public static function getStoredRelationsRecord(int $elementId, int $siteId) {
        return ElementRelationsRecord::find()
            ->where(['elementId'=>$elementId])
            ->andWhere(['siteId' => $siteId])
            ->one();
    }

    /**
     * @param int $elementId
     * @param int $siteId
     * @return array
     */
    public static function getRelatedRelationsRecords(int $elementId, int $siteId): array
    {
        return ElementRelationsRecord::find()
            ->where(['like','relations', $elementId])
            ->andWhere(['siteId' => $siteId])
            ->all();
    }

    /**
     * @param ElementRelationsRecord $record
     * @throws \Throwable
     * @throws \yii\db\StaleObjectException
     */
    public static function deleteRelationsRecord(ElementRelationsRecord $record) {
        $record->delete();
    }

    /**
     * @param int $elementId
     * @param int $siteId
     * @param string $resultHtml
     * @param ElementRelationsRecord $elementRelationsRecord
     */
    public static function setStoredRelations(int $elementId, int $siteId, array $relations, string $resultHtml)
    {
        if (!$elementRelationsRecord = self::getStoredRelationsRecord($elementId, $siteId)) {
            $elementRelationsRecord = new ElementRelationsRecord();
        }
        $elementRelationsRecord->setAttribute('elementId', $elementId);
        $elementRelationsRecord->setAttribute('siteId', $siteId);
        $elementRelationsRecord->setAttribute('relations', implode(',', $relations));
        $elementRelationsRecord->setAttribute('resultHtml', $resultHtml);
        $elementRelationsRecord->save();
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

        return collect($elements)->map(function(int $elementId) use ($site) {
            /** @var ?Element $relation */
            $relation = self::getElementById($elementId, $site);
            if (!$relation) { return null; }
            return self::getRootElement($relation, $site);
        })->filter()->values()->toArray();
    }

    /**
     * @param Entry $entry
     * @throws \Throwable
     * @throws \yii\db\StaleObjectException
     */
    public static function clearRelatedCaches(Entry $entry) {
        $relationsRecords = self::getRelatedRelationsRecords($entry->id, $entry->siteId);
        foreach ($relationsRecords as $record) {
            self::deleteRelationsRecord($record);
        }
    }

    /**
     * @param int $elementId
     * @param $site
     * @return Element|null
     */
    private static function getElementById (int $elementId, $site): ?Element
    {
        if (is_numeric($site)) {
            $site = \Craft::$app->sites->getSiteById($site);
        }
        $result = (new Query())->select(['type'])->from(Table::ELEMENTS)->where(['id' => $elementId])->one();
        if (!$result) { return null; } // relation is broken
        return $result['type']::find()->id($elementId)->anyStatus()->site($site)->one();
    }

    /**
     * @param Element $sourceElement
     * @return array|false
     */
    public static function isUsedInSEOmatic(Element $sourceElement)
    {
        $result = ['usedGlobally' => false, 'elements' => []];
        $isInstalled = \Craft::$app->db->tableExists('{{%seomatic_metabundles}}');
        if (!$isInstalled) { return false; }

        $extractIdFromString = function ($input) {
            if (!$input) { return; }
            $result = sscanf($input, '{seomatic.helper.socialTransform(%d, ');
            return (int) collect($result)->first();
        };

        $globalQueryResult = (new Query)->select(['metaGlobalVars', 'metaSiteVars'])
            ->from('{{%seomatic_metabundles}}')
            ->all();

        $result['usedGlobally'] = collect($globalQueryResult)
            ->map(function($row) { return collect($row)->values(); })
            ->flatten()
            ->map(function($row) { return json_decode($row, true); })
            ->map(function ($row) use ($extractIdFromString) {
                if (isset($row['seoImage'])) { return $extractIdFromString($row['seoImage']); }
                if (isset($row['identity']['genericImageIds'])) { return $row['identity']['genericImageIds']; }
                return null;
            })
            ->flatten()->filter()
            ->map(function($row) { return (int) $row; })->unique()
            ->contains($sourceElement->id);

        $fields = (new Query)->select(['handle'])
            ->from(Table::FIELDS)
            ->where(['=', 'type', 'nystudio107\seomatic\fields\SeoSettings'])
            ->column();

        $foundElements = collect();

        collect($fields)->each(function ($handle) use (&$foundElements, $extractIdFromString, $sourceElement) {
            $fieldHandle = sprintf('field_%s', $handle);
            $rows = (new Query)->select(['elements.canonicalId', 'elements.id', 'siteId', 'title', 'content.'.$fieldHandle])
                ->from(['content'=> Table::CONTENT])
                ->innerJoin(['elements'=>Table::ELEMENTS], '[[elements.id]] = [[content.elementId]]')
                ->where(['NOT', ['content.'.$fieldHandle => null]])
                ->all();
            collect($rows)->each(function ($row) use (&$foundElements, $extractIdFromString, $fieldHandle, $sourceElement) {
                $data = json_decode($row[$fieldHandle]);
                $id = $extractIdFromString($data->metaGlobalVars->seoImage);
                if ($id !== $sourceElement->id) { return; }
                $foundElements->push(self::getElementById($row['canonicalId'] ?? $row['id'], $row['siteId']));
            });
        });
        $result['elements'] = collect($foundElements)
            ->unique('canonialId')
            ->toArray();
        return $result;
    }

    /**
     * @param Element $element
     * @param $site
     * @return Element|null
     */
    private static function getRootElement (Element $element, $site): ?Element
    {
        if (!isset($element->ownerId) || !$element->ownerId) { return $element; }
        $sourceElement = self::getElementById($element->ownerId, $site);
        if (!$sourceElement) { return null; }
        return self::getRootElement($sourceElement, $site);
    }
}
