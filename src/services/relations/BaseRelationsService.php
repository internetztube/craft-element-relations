<?php

namespace internetztube\elementRelations\services\relations;

use craft\elements\db\ElementQuery;

class BaseRelationsService
{
    public static function getElementsByBaseElementInfo(array $baseElementInfo): array
    {
        return collect($baseElementInfo)
            ->groupBy('type')
            ->map(function ($values, $elementType) {
                $whereStatements = collect($values)
                    ->map(fn($value) => [
                        'elements.id' => $value['elementId'],
                        'elements_sites.siteId' => $value['siteId']
                    ]);

                /** @var ElementQuery $query */
                $query = $elementType::find()->where(['or', ...$whereStatements]);

                return [
                    ...$query->all(),
                    ...$query->drafts()->all()
                ];
            })
            ->flatten()
            ->filter()
            ->toArray();
    }
}