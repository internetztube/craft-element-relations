<?php

namespace internetztube\elementRelations\services;

use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;

class ResaveRelationsService
{
    public static function resave(callable $progressCallback = null): void
    {

        $query = (new Query())
            ->select([
                'elements_sites.elementId',
                'elements_sites.siteId',
                'elements.type'
            ])
            ->from(['elements_sites' => Table::ELEMENTS_SITES])
            ->innerJoin(['elements' => Table::ELEMENTS], "[[elements.id]] = [[elements_sites.elementId]]")
            ->where(['is', 'elements.dateDeleted', null]);

        $totalCount = (clone $query)->count();
        $index = 0;

        foreach (Db::each($query, 200) as $row) {
            $element = \Craft::$app->getElements()->getElementById($row['elementId'], trim($row['type']), $row['siteId']);
            $index += 1;
            if (!$element) {
                $progressCallback && $progressCallback($index, $totalCount, json_encode($row));
                continue;
            }
            $progressCallback && $progressCallback($index, $totalCount, $element::class);
            ExtractorService::refreshRelationsForElement($element);
        }
    }
}