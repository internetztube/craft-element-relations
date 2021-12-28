<?php

namespace internetztube\elementRelations\services;

use craft\base\Element;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\models\ElementRelationsModel;
use internetztube\elementRelations\records\ElementRelationsRecord;
use Throwable;
use yii\db\ActiveRecord;
use yii\db\StaleObjectException;

class CacheService
{
    // Fallback Cache Duration
    private const DEFAULT_CACHE_DURATION = '1 week';

    /**
     * Get Element Relations from cache.
     * @param Element $element
     * @param bool $force
     * @return string
     */
    public static function getElementRelationsCached(Element $element, bool $force = false): string
    {
        $staleDateTime = self::getStaleDateTime();
        $cachedRelations = self::getStoredRelations($element->id, $element->siteId);
        $stale = !empty($cachedRelations) && $cachedRelations->dateUpdated < $staleDateTime;
        $useCache = self::useCache();
        $gatherElementRelations = $force || !$cachedRelations || $stale || !$useCache;

        if ($gatherElementRelations) {
            $relations = ElementRelationsService::getElementRelations($element);
            if ($useCache) {
                self::setStoredRelations($element->id, $element->siteId, $relations['elementIds'], $relations['markup']);
            }
            return $relations['markup'];
        }
        return $cachedRelations->getMarkup();
    }

    /**
     * Get the datetime used for determining a stale cache.
     * @return string
     */
    private static function getStaleDateTime(): string
    {
        $cacheDuration = self::getCacheDuration();
        return date('Y-m-d H:i:s', strtotime("$cacheDuration ago"));
    }

    /**
     * Get cache duration from config, settings, or class.
     * @return string
     */
    public static function getCacheDuration(): string
    {
        $settings = ElementRelations::$plugin->getSettings();
        return $settings->cacheDuration ?? self::DEFAULT_CACHE_DURATION;
    }

    /**
     * Get cached relations for an element.
     * @param int $elementId
     * @param int $siteId
     * @return ElementRelationsModel|null
     */
    private static function getStoredRelations(int $elementId, int $siteId): ?ElementRelationsModel
    {
        $elementRelationsRecord = self::getStoredRelationsRecord($elementId, $siteId);
        $elementRelationsModel = new ElementRelationsModel();
        if (!$elementRelationsRecord) { return null; }
        $attributes = $elementRelationsRecord->getAttributes();
        $elementRelationsModel->setAttributes($attributes, false);
        return $elementRelationsModel;
    }

    public static function getCountCachedElementRelations(): int
    {
        return ElementRelationsRecord::find()->count();
    }

    /**
     * Get an ElementRelationsRecord ActiveRecord for an element.
     * @param int $elementId
     * @param int $siteId
     * @return array|ElementRelationsRecord|ActiveRecord|null
     */
    private static function getStoredRelationsRecord(int $elementId, int $siteId): ?ElementRelationsRecord
    {
        return ElementRelationsRecord::find()
            ->where(['elementId' => $elementId])
            ->andWhere(['siteId' => $siteId])
            ->one();
    }

    /**
     * Is Caching enabled in settings?
     * @return bool
     */
    public static function useCache(): bool
    {
        $settings = ElementRelations::$plugin->getSettings();
        return $settings->useCache;
    }

    /**
     * Add the relation to the db. Note dateUpdated is added here because otherwise if nothing changes,
     * the record is not updated, and we use that for cache staleness.
     * @param int $elementId
     * @param int $siteId
     * @param array $relations
     * @param string $markup
     */
    private static function setStoredRelations(int $elementId, int $siteId, array $relations, string $markup)
    {
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

    /**
     * Get all cached related elements of one element.
     * @param int $elementId
     * @param int $siteId
     * @return array
     */
    public static function getRelatedElementRelations(int $elementId, int $siteId): array
    {
        if (!self::useCache()) { return []; }
        $records = ElementRelationsRecord::find()
            ->where(['like', 'relations', $elementId])
            ->andWhere(['siteId' => $siteId])
            ->all();
        return collect($records)->map(function (ElementRelationsRecord $record) {
            return ['elementId' => $record->elementId, 'siteId' => $record->siteId];
        })->all();
    }

    /**
     * Delete a ElementsRelationsRecord when caching is enabled.
     * @param int $elementId
     * @param int $siteId
     * @throws StaleObjectException
     * @throws Throwable
     */
    public static function deleteElementRelationsRecord(int $elementId, int $siteId): void
    {
        if (!self::useCache()) { return; }
        $record = self::getStoredRelationsRecord($elementId, $siteId);
        $record->delete();
    }
}