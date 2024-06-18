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

        /** @var Collection $rows */
        $rows = (new Query())
            ->select([
                'elements_sites.elementId',
                'elements_sites.siteId',
                'elements.type'
            ])
            ->from(['elements_sites' => Table::ELEMENTS_SITES])
            ->innerJoin(['elements' => Table::ELEMENTS], "[[elements.id]] = [[elements_sites.elementId]]")
            ->where(['is', 'elements.dateDeleted', null])
            ->collect();

        $totalCount = $rows->count();

        $rows->each(function (array $row, int $index) use (&$totalCount, $progressCallback) {
            $element = \Craft::$app->getElements()->getElementById($row['elementId'], null, $row['siteId']);
            $progressCallback && $progressCallback($index+1, $totalCount, $element::class);
            ExtractorService::refreshRelationsForElement($element);
        });
    }
}