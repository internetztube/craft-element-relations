<?php

namespace internetztube\elementRelations\services;

use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use Illuminate\Support\Collection;

class ResaveRelationsService
{
    public static function resave(callable $progressCallback = null): void
    {
        $totalCount = 0;

        /** @var Collection $queries */
        $queries = (new Query())
            ->select("type")
            ->from(Table::ELEMENTS)
            ->groupBy("type")
            ->collect()
            ->pluck("type")
            ->map(function (string $elementType) use (&$totalCount) {
                if (!class_exists($elementType)) {
                    return [];
                }

                /** @var ElementQuery $query */
                $query = $elementType::find();
                $query->status(null)->site('*');

                $defaultQuery = clone $query;
                $draftQuery = (clone $query)->drafts();

                try {
                    $localCount = 0;
                    $localCount += (clone $defaultQuery)->count();
                    $localCount += (clone $draftQuery)->count();
                    $totalCount += $localCount;
                    return [$defaultQuery, $draftQuery];
                } catch (\Exception $e) {
                    echo $e->getMessage();
                    return null;
                }
            })
            ->flatten(1)
            ->filter();

        $index = 0;

        $queries->map(function (ElementQuery $elementQuery) use (&$index, $totalCount, $progressCallback) {
            foreach ($elementQuery->each() as $item) {
                $progressCallback && $progressCallback($index, $totalCount, $item::class);
                ExtractorService::refreshRelationsForElement($item);
                $index += 1;
            }
        });
    }
}