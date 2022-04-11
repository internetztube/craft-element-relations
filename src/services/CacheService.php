<?php

namespace internetztube\elementRelations\services;

use Craft;
use craft\helpers\Db;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\records\ElementRelationsRecord;
use Throwable;
use yii\db\Query;
use yii\db\StaleObjectException;

class CacheService
{
    // Fallback Cache Duration
    private const DEFAULT_CACHE_DURATION = '1 week';

    /**
     * Get cached stringified element relations.
     * @param int $elementId
     * @param bool $force
     * @return string
     */
    public static function getElementRelationsCached(int $elementId, bool $force = false): string
    {
        $useCache = self::useCache();
        $validCachedRelations = self::getNonStaleStoredRelations($elementId);
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

    public static function getDateUpdatedFromElementRelations(int $elementId): ?string
    {
        if (!self::useCache()) {
            return null;
        }
        $record = self::getBaseQueryForNonStaleRecords()
            ->andWhere(['elementId' => $elementId])
            ->one();
        if (!$record) {
            return null;
        }
        return $record->dateUpdated;
    }

    /**
     * Has a element a non-stale element relations cache record?
     * @param int $elementId
     * @return bool
     */
    public static function hasStoredElementRelations(int $elementId): bool
    {
        return !!self::getNonStaleStoredRelations($elementId);
    }

    /**
     * Is Caching enabled in settings?
     * @return bool
     */
    public static function useCache(): bool
    {
        return !!ElementRelations::$plugin->getSettings()->useCache;
    }

    /**
     * Get cached relations for an element.
     * @param int $elementId
     * @return string|null
     */
    private static function getNonStaleStoredRelations(int $elementId): ?string
    {
        $row = self::getBaseQueryForNonStaleRecords()
            ->andWhere(['elementId' => $elementId])
            ->one();
        return $row ? $row->relations : null;
    }

    /**
     * Query that returns all non stale relations.
     * @return Query
     */
    private static function getBaseQueryForNonStaleRecords(): Query
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
    public static function getCacheDuration(): ?string
    {
        if (!self::useCache()) {
            return null;
        }
        $settings = ElementRelations::$plugin->getSettings();
        return $settings->cacheDuration ?? self::DEFAULT_CACHE_DURATION;
    }

    /**
     * Add the relation to the db. Note dateUpdated is added here because otherwise if nothing changes,
     * @param int $elementId
     * @param string $relations
     */
    private static function setStoredRelations(int $elementId, string $relations): void
    {
        if (!Craft::$app->elements->getElementById($elementId)) {
            return;
        }
        if (!$elementRelationsRecord = self::getStoredRelationsRecord($elementId)) {
            $elementRelationsRecord = new ElementRelationsRecord();
        }
        $elementRelationsRecord->setAttribute('elementId', $elementId);
        $elementRelationsRecord->setAttribute('relations', $relations);
        $elementRelationsRecord->setAttribute('dateUpdated', date('Y-m-d H:i:s'));
        $elementRelationsRecord->save();
    }

    /**
     * Get an ElementRelationsRecord ActiveRecord for an element.
     * @param int $elementId
     * @return ElementRelationsRecord|null
     */
    private static function getStoredRelationsRecord(int $elementId): ?ElementRelationsRecord
    {
        return ElementRelationsRecord::find()
            ->where(['elementId' => $elementId])
            ->one();
    }

    /**
     * Get Count of non stale element relations.
     * @return int
     */
    public static function getCountOfNonStaleElementRelations(): int
    {
        if (!self::useCache()) {
            return 0;
        }
        return self::getBaseQueryForNonStaleRecords()->count();
    }

    /**
     * Get all cached related elements of one element.
     * @param $identifier
     * @return int[]
     */
    public static function getRelatedElementRelations($identifier): array
    {
        if (!self::useCache()) {
            return [];
        }
        $like = sprintf('%s%s%s', ElementRelationsService::IDENTIFIER_DELIMITER, $identifier, ElementRelationsService::IDENTIFIER_DELIMITER);
        $records = self::getBaseQueryForNonStaleRecords()->andWhere(['like', 'relations', $like])->all();
        return collect($records)->pluck('elementId')
            ->map(function ($row) {
                return (int)$row;
            })
            ->all();
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