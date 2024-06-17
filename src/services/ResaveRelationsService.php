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
        /** @var Collection $queries */
        $queries = (new Query())
            ->select("type")
            ->from(Table::ELEMENTS)
            ->groupBy("type")
            ->collect()
            ->pluck("type")
            ->map(function (string $elementType) {
                if (!class_exists($elementType)) {
                    return [];
                }

                /** @var ElementQuery $query */
                $query = $elementType::find();
                $query->status(null)->site('*');
                return [clone $query, (clone $query)->drafts()];
            })
            ->flatten(1);

        $totalCount = $queries
            ->map(fn (ElementQuery $query) => $query->count())
            ->sum();

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