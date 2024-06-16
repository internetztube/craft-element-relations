<?php

namespace internetztube\elementRelations\services\extractors;

use craft\base\ElementInterface;
use craft\base\Field;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

class FieldExtractorRedactorCkEditorService implements InterfaceFieldExtractor
{
    /**
     * @param Field $field
     * @param ElementInterface $element
     * @param ElementRelationsCacheRecord $baseRecord
     * @return ElementRelationsCacheRecord[]|false
     */
    public static function getRelations(Field $field, ElementInterface $element, ElementRelationsCacheRecord $baseRecord): array|false
    {
        if (!($field instanceof \craft\redactor\Field) && !($field instanceof \craft\ckeditor\Field)) {
            return false;
        }

        /** @var \craft\redactor\FieldData|\craft\ckeditor\data\FieldData $fieldData */
        $fieldData = $element->{$field->handle};
        $data = $fieldData?->getRawContent() ?? '';

        return collect(explode("{", $data))
            ->map(fn (string $row) => explode('}', $row))
            ->flatten()
            ->filter(fn (string $row) => strstr($row, '||'))
            ->map(fn (string $row) => explode('||', $row)[0])
            ->map(fn (string $row) => explode(':', $row)[1])
            ->map(function (string $row) use ($element, $baseRecord, $field) {
                $siteId = str_contains($row, '@') ? explode('@', $row)[1] : $element->siteId;
                $elementId = explode('@', $row)[0];

                if (!$elementId || !$siteId) {
                    return null;
                }

                $record = clone $baseRecord;
                $record->setAttributes([
                    'type' => ElementRelationsCacheRecord::TYPE_FIELD,
                    'targetElementId' => $elementId,
                    'targetSiteId' => $siteId,
                    'customFieldUid' => $field->uid,
                    'fieldId' => $field->id,
                ]);
                return $record;
            })
            ->filter()
            ->values()
            ->all();
    }
}