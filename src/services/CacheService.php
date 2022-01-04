<?php

namespace internetztube\elementRelations\services;

use craft\base\Element;
use craft\helpers\Db;
use Exception;
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
    public static function getElementRelationsCached(int $elementId, bool $force = false): string
    {
        $useCache = self::useCache();
        $validCachedRelations = self::getStoredRelations($elementId);
        $gatherElementRelations = $force || !$useCache || !$validCachedRelations;
        if ($gatherElementRelations) {
            $relations = ElementRelationsService::getElementRelations($elementId);
            if ($useCache) {
                self::setStoredRelations($elementId, $relations);
            }
            return $relations;
        }
        return $validCachedRelations;
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
     * Get cached relations for an element.
     * @param int $elementId
     * @param int $siteId
     * @return ElementRelationsModel|null
     */
    private static function getStoredRelations(int $elementId): ?string
    {
        $row = self::getBaseQuery()
            ->andWhere(['elementId' => $elementId])
            ->one();
        return $row ? $row->relations : null;
    }

    private static function getBaseQuery()
    {
        return ElementRelationsRecord::find()
            ->where(['>=', 'dateUpdated', Db::prepareDateForDb(self::getStaleDateTime())]);
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
     * Add the relation to the db. Note dateUpdated is added here because otherwise if nothing changes,
     * the record is not updated, and we use that for cache staleness.
     * @param int $elementId
     * @param int $siteId
     * @param array $relations
     * @param string $markup
     */
    private static function setStoredRelations(int $elementId, string $relations): bool
    {
        if (!$elementRelationsRecord = self::getStoredRelationsRecord($elementId)) {
            $elementRelationsRecord = new ElementRelationsRecord();
        }
        $elementRelationsRecord->setAttribute('elementId', $elementId);
        $elementRelationsRecord->setAttribute('relations', $relations);
        $elementRelationsRecord->setAttribute('dateUpdated', date('Y-m-d H:i:s'));

        try {
            return $elementRelationsRecord->save();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get an ElementRelationsRecord ActiveRecord for an element.
     * @param int $elementId
     * @param int $siteId
     * @return array|ElementRelationsRecord|ActiveRecord|null
     */
    private static function getStoredRelationsRecord(int $elementId): ?ElementRelationsRecord
    {
        return ElementRelationsRecord::find()
            ->where(['elementId' => $elementId])
            ->one();
    }

    public static function getCountCachedElementRelations(): int
    {
        return self::getBaseQuery()->count();
    }

    /**r
     * Get all cached related elements of one element.
     * @param int $elementId
     * @param int $siteId
     * @return array
     */
    public static function getRelatedElementRelations($identifier): array
    {
        if (!self::useCache()) {
            return [];
        }
        $like = sprintf('%s%s%s', ElementRelationsService::IDENTIFIER_DELIMITER, $identifier, ElementRelationsService::IDENTIFIER_DELIMITER);
        $records = self::getBaseQuery()->andWhere(['like', 'relations', $like])->all();
        return collect($records)->pluck('elementId')->all();
    }

    /**
     * Delete a ElementsRelationsRecord when caching is enabled.
     * @param int $elementId
     * @param int $siteId
     * @throws StaleObjectException
     * @throws Throwable
     */
    public static function deleteElementRelationsRecord(int $elementId): void
    {
        if (!self::useCache()) {
            return;
        }
        $record = self::getStoredRelationsRecord($elementId);
        $record->delete();
    }
}