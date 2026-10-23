<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Services;

use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Jobs\ResaveElementRelationsJob;

/** Collects the elements saved during a request and refreshes their relations in queue jobs of `bulkRefreshBatchSize`. */
class QueueElementsForRefreshService
{
    /** @var list<array{elementId: int, siteId: int}> */
    private static array $items = [];

    public static function registerForRefresh(int $elementId, int $siteId): void
    {
        self::$items[] = ['elementId' => $elementId, 'siteId' => $siteId];
        if (count(self::$items) >= (ElementRelations::instance()?->getSettings()->bulkRefreshBatchSize ?? 2000)) {
            self::pushToQueue();
        }
    }

    /**
     * Drops the elements from the pending refreshes, in every site: they were just refreshed right away (e.g. by a
     * replacement), so a queue job would only repeat the work.
     * @param int[] $elementIds
     */
    public static function forget(array $elementIds): void
    {
        if (!$elementIds) {
            return;
        }
        $forget = array_flip($elementIds);
        self::$items = array_values(array_filter(self::$items, fn(array $item): bool => !isset($forget[$item['elementId']])));
    }

    /** Pushes what is pending (end of a request, after a queue job). */
    public static function destruct(): void
    {
        if (self::$items) {
            self::pushToQueue();
        }
    }

    private static function pushToQueue(): void
    {
        $items = self::$items;
        self::$items = [];
        dispatch(new ResaveElementRelationsJob($items));
    }
}
