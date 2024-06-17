<?php

namespace internetztube\elementRelations\services\extractors;

use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\db\ElementQuery;
use craft\fields\BaseRelationField;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

class FieldExtractorBaseRelationService implements InterfaceFieldExtractor
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

        /** @var Element $entries */
        $elements = [
            ...$query->all(),
            ...$query->drafts()->all(),
        ];

        return collect($elements)
            ->map(function (Element $element) use ($field, $baseRecord) {
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
}