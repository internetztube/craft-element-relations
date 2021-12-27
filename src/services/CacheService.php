<?php

namespace internetztube\elementRelations\services;

use Throwable;
use craft\base\Element;
use craft\base\ElementInterface;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\models\ElementRelationsModel;
use internetztube\elementRelations\records\ElementRelationsRecord;
use yii\base\Component;
use yii\db\ActiveRecord;
use yii\db\StaleObjectException;
use yii\web\NotFoundHttpException;

class CacheService extends Component
{
    private const DEFAULT_CACHE_DURATION = '1 week';

    public static function refreshRelatedElementRelations(int $elementId, int $siteId): void
    {

    }

    /**
     * @param Element|ElementInterface $element
     * @param int $elementId
     * @param int $siteId
     * @param string $size
     * @param bool $force Force the record to re-cache
     * @return string|bool
     */
    public static function getRelationsCached(Element $element, bool $force = false): string
    {
        $staleDateTime = self::getStaleDateTime();
        $cachedRelations = self::getStoredRelations($element->id, $element->siteId);
        $stale = !empty($cachedRelations) && $cachedRelations->dateUpdated < $staleDateTime;

        if ($force || !$cachedRelations || $stale) {
            $relations = ElementRelationsService::getRelations($element);
            // set it or update it
            self::setStoredRelations($element->id, $element->siteId, $relations['elementIds'], $relations['markup']);
            return $relations['markup'];
        }
        return $cachedRelations->getMarkup();
    }

    /**
     * @param int $elementId
     * @param int $siteId
     * @return ActiveRecord[]
     */
    public static function getRelatedRelationsRecords(int $elementId, int $siteId): array
    {
        if (!self::getUseCache()) { return []; }
        return ElementRelationsRecord::find()
            ->where(['like', 'relations', $elementId])
            ->andWhere(['siteId' => $siteId])
            ->all();
    }

    /**
     * @return bool
     */
    public static function getUseCache(): bool
    {
        $settings = ElementRelations::$plugin->getSettings();
        return $settings->useCache;
    }

    public static function deleteRelationsRecord(ElementRelationsRecord $record): void
    {
        if (!self::getUseCache()) { return; }
        $record->delete();
    }

    /**
     * Get the datetime used for determining a stale cache
     * @return string
     */
    private static function getStaleDateTime(): string
    {
        $cacheDuration = self::getCacheDuration();
        return date('Y-m-d H:i:s', strtotime("$cacheDuration ago"));
    }

    /**
     * @return string Cache Duration from config, settings, or class
     */
    public static function getCacheDuration(): string
    {
        $settings = ElementRelations::$plugin->getSettings();
        return $settings->cacheDuration ?? self::DEFAULT_CACHE_DURATION;
    }

    /**
     * @param int $elementId
     * @param int $siteId
     * @return ElementRelationsModel|null
     */
    private static function getStoredRelations(int $elementId, int $siteId): ?ElementRelationsModel
    {
        if (!self::getUseCache()) { return null; }
        $elementRelationsRecord = self::getStoredRelationsRecord($elementId, $siteId);
        $elementRelationsModel = new ElementRelationsModel();
        if (!$elementRelationsRecord) { return null; }
        $attributes = $elementRelationsRecord->getAttributes();
        $elementRelationsModel->setAttributes($attributes, false);
        return $elementRelationsModel;
    }

    /**
     * @param int $elementId
     * @param int $siteId
     * @return array|ActiveRecord|null
     */
    private static function getStoredRelationsRecord(int $elementId, int $siteId)
    {
        if (!self::getUseCache()) { return null; }
        return ElementRelationsRecord::find()
            ->where(['elementId' => $elementId])
            ->andWhere(['siteId' => $siteId])
            ->one();
    }

    /**
     * Add the relation to the db. Note dateUpdated is added here because
     * otherwise if nothing changes, the record is not updated, and we use that for cache staleness
     * @param int $elementId
     * @param int $siteId
     * @param array $relations
     * @param string $markup
     */
    private static function setStoredRelations(int $elementId, int $siteId, array $relations, string $markup)
    {
        if (!self::getUseCache()) { return; }
        if (!$elementRelationsRecord = self::getStoredRelationsRecord($elementId, $siteId)) {
            $elementRelationsRecord = new ElementRelationsRecord();
        }
        $elementRelationsRecord->setAttribute('elementId', $elementId);
        $elementRelationsRecord->setAttribute('siteId', $siteId);
        $elementRelationsRecord->setAttribute('relations', implode(',', $relations));
        $elementRelationsRecord->setAttribute('markup', $markup);
        $elementRelationsRecord->setAttribute('dateUpdated', date('Y-m-d H:i:s'));
        $elementRelationsRecord->save();
    }
}