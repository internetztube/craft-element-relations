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
                $query = $elementType::find()->status(null);
                return [
                    ['type' => $elementType, 'query' => clone $query],
                    ['type' => $elementType, 'query' => (clone $query)->drafts()],
                ];
            })
            ->flatten(1);

        $totalCount = $queries
            ->pluck('query')
            ->map(fn (Query $query) => $query->count())
            ->sum();

        $index = 0;

        $queries->map(function (array $item) use (&$index, $totalCount, $progressCallback) {
            $limit = 100;
            /** @var ElementQuery $elementQuery */
            $elementQuery = $item["query"];
            $elementQuery = (clone $elementQuery)->limit($limit);
            $count = (int) (clone $elementQuery)->count();

            for ($i = 0; $i < $count; $i += $limit) {
                $result = (clone $elementQuery)->offset($i)->collect();
                $index += $result->count();
                $progressCallback && $progressCallback($index, $totalCount, $item["type"]);
                $result->each(fn (ElementInterface $element) => ExtractorService::refreshRelationsForElement($element));
            }
        });
    }
}