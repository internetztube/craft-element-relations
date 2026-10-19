<?php

namespace internetztube\elementRelations\services\extractors;

use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\db\ElementQuery;
use craft\fields\BaseRelationField;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

class FieldExtractorBaseRelationService implements InterfaceFieldExtractor, InterfaceFieldReplacer
{
    /**
     * @param Field $field
     * @param ElementInterface $element
     * @param ElementRelationsCacheRecord $baseRecord
     * @return ElementRelationsCacheRecord[]|false
     */
    public static function getRelations(Field $field, ElementInterface $element, ElementRelationsCacheRecord $baseRecord): array|false
    {
        if (!($field instanceof BaseRelationField)) {
            return false;
        }

        /** @var ElementQuery $query */
        $query = $element->{$field->handle}->status(null);

        $elements = [
            ...$query->all(),
            ...$query->drafts()->all(),
        ];

        return collect($elements)
            ->map(function (ElementInterface $element) use ($field, $baseRecord) {
                $record = clone $baseRecord;
                $record->setAttributes([
                    'type' => ElementRelationsCacheRecord::TYPE_FIELD,
                    'targetElementId' => $element->id,
                    'targetSiteId' => $element->siteId,
                    'customFieldUid' => $field->uid,
                    'fieldId' => $field->id,
                ]);
                return $record;
            })
            ->all();
    }

    public static function replace(Field $field, ElementInterface $element, ElementInterface $from, ElementInterface $to): ?bool
    {
        if (!($field instanceof BaseRelationField)) {
            return null;
        }
        $ids = array_map('intval', $element->getFieldValue($field->handle)->status(null)->ids());
        if (!in_array($from->id, $ids, true)) {
            return false;
        }
        $element->setFieldValue($field->handle, array_values(array_unique(array_map(fn(int $id) => $id === $from->id ? $to->id : $id, $ids))));
        return true;
    }
}
