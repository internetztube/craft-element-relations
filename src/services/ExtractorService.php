<?php

namespace internetztube\elementRelations\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;
use internetztube\elementRelations\services\extractors\ElementExtractorUserPhotoService;
use internetztube\elementRelations\services\extractors\FieldExtractorBaseRelationService;
use internetztube\elementRelations\services\extractors\FieldExtractorHyperService;
use internetztube\elementRelations\services\extractors\FieldExtractorLinkItService;
use internetztube\elementRelations\services\extractors\FieldExtractorRedactorCkEditorService;
use internetztube\elementRelations\services\extractors\FieldExtractorSeomaticService;
use internetztube\elementRelations\services\extractors\FieldExtractorTypedLinkFieldService;

class ExtractorService
{
    /**
     * @param ElementInterface $element
     * @return bool
     * @throws \yii\db\Exception
     */
    public static function refreshRelationsForElement(ElementInterface $element): bool
    {
        $fieldLayout = $element->getFieldLayout();
        if (!$fieldLayout) {
            return false;
        }
        $fields = $fieldLayout->getCustomFields();
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
                if ($records = FieldExtractorTypedLinkFieldService::getRelations($field, $element, clone $baseRecord)) {
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
        while (
            property_exists($element, 'primaryOwnerId')
            && $element->primaryOwnerId
            && $newElement = Craft::$app->getElements()->getElementById($element->primaryOwnerId, null, $element->siteId)
        ) {
            $element = $newElement;
        }
        return $element;
    }
}