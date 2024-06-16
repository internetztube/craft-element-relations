<?php

namespace internetztube\elementRelations\services;

use craft\base\ElementInterface;
use craft\base\Field;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;
use internetztube\elementRelations\services\extractors\ElementExtractorUserPhotoService;
use internetztube\elementRelations\services\extractors\FieldExtractorBaseRelationService;
use internetztube\elementRelations\services\extractors\FieldExtractorCkEditorService;
use internetztube\elementRelations\services\extractors\FieldExtractorHyperService;
use internetztube\elementRelations\services\extractors\FieldExtractorLinkItService;
use internetztube\elementRelations\services\extractors\FieldExtractorRedactorCkEditorService;
use internetztube\elementRelations\services\extractors\FieldExtractorSeomaticService;

class RelationsExtractorService
{
    public static function getRelations(ElementInterface $element)
    {
        $fields = $element->getFieldLayout()?->getCustomFields();
        if (!$fields) {
            return false;
        }
        $sourcePrimaryOwner = self::getPrimaryOwner($element);

        $baseRecord = new ElementRelationsCacheRecord([
            'sourceElementId' => $element->id,
            'sourceSiteId' => $element->siteId,
            'sourcePrimaryOwnerId' => $sourcePrimaryOwner->id,
        ]);

        $records = collect($fields)
            ->map(function (Field $field) use ($element, $baseRecord) {
                if ($records = FieldExtractorBaseRelationService::getRelations($field, $element, clone $baseRecord)) {
                    return $records;
                }
                if ($records = FieldExtractorHyperService::getRelations($field, $element, clone $baseRecord)) {
                    return $records;
                }
                if ($records = FieldExtractorSeomaticService::getRelations($field, $element, clone $baseRecord)) {
                    return $records;
                }
                if ($records = FieldExtractorLinkItService::getRelations($field, $element, clone $baseRecord)) {
                    return $records;
                }
                if ($records = FieldExtractorRedactorCkEditorService::getRelations($field, $element, clone $baseRecord)) {
                    return $records;
                }
                return null;
            })
            ->flatten()
            ->merge([
                ElementExtractorUserPhotoService::getRelations($element, clone $baseRecord)
            ])
            ->filter();

        ElementRelationsCacheRecord::deleteAll([
            'sourceElementId' => $element->id,
            'sourceSiteId' => $element->siteId,
        ]);

        $records->each(fn(ElementRelationsCacheRecord $record) => $record->save());
        return true;
    }

    private static function getPrimaryOwner(ElementInterface $element): ElementInterface
    {
        while (method_exists($element, 'getPrimaryOwner') && $primaryOwner = $element->getPrimaryOwner()) {
            $element = $primaryOwner;
        }
        return $element;
    }
}