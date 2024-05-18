<?php

namespace internetztube\elementRelations\services;

use internetztube\elementRelations\records\ElementRelationsRecord;
use nystudio107\seomatic\records\MetaBundle as SeomaticMetaBundleRecord;

class SeomaticGlobalRelationsService
{
    public const RELATION_TYPE = 'seomatic-global';

    public function updateRelations()
    {
        ElementRelationsRecord::deleteAll([
            'type' => self::RELATION_TYPE,
        ]);

        $records = SeomaticMetaBundleRecord::find()
            ->select(['sourceSiteId', 'metaGlobalVars', 'metaSiteVars'])
            ->all();

        collect($records)->map(function (SeomaticMetaBundleRecord $record) {
            $siteId = $record->sourceSiteId;

            return collect([$record['metaGlobalVars'], $record['metaSiteVars']])
                ->map(function ($row) {
                    return json_decode($row, true);
                })
                ->map(function ($row) use ($siteId) {
                    $result = collect();

                    if (isset($row['seoImage'])) {
                        $resultExtraction = SeomaticLocalRelationsService::extractElementIdSiteIdFromString($row['seoImage']);
                        if (!$resultExtraction) {
                            return null;
                        }
                        $result->push($resultExtraction);
                    }

                    if (isset($row['identity']['genericImageIds'])) {
                        $genericImageIds = collect($row['identity']['genericImageIds'])
                            ->each(function ($elementId) use ($siteId, $result) {
                                if (!$elementId) return;
                                $result->push(['elementId' => (int)$elementId, 'siteId' => (int)$siteId]);
                            });
                        $result->merge($genericImageIds);
                    }

                    if (isset($row['creator']['genericImageIds'])) {
                        $genericImageIds = collect($row['creator']['genericImageIds'])
                            ->each(function ($elementId) use ($siteId, $result) {
                                if (!$elementId) return;
                                $result->push(['elementId' => (int)$elementId, 'siteId' => (int)$siteId]);
                            });
                        $result->merge($genericImageIds);
                    }

                    return $result;
                })
                ->flatten(1)
                ->filter()
                ->all();
        })
            ->filter()
            ->flatten(1)
            ->unique()
            ->each(function (array $item) {
                $record = new ElementRelationsRecord([
                    'type' => self::RELATION_TYPE,
                    'targetElementId' => $item['elementId'],
                    'targetSiteId' => $item['siteId'],
                ]);
                $record->save();
            });
    }
}