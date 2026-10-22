<?php

declare(strict_types=1);

namespace internetztube\elementRelations\services\extractors;

use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\db\ElementQuery;
use craft\elements\db\ElementQueryInterface;
use craft\fields\BaseRelationField;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

class FieldExtractorBaseRelationService implements InterfaceFieldExtractor, InterfaceFieldReplacer
{
    /** @return list<ElementRelationsCacheRecord>|false */
    public static function getRelations(Field $field, ElementInterface $element, ElementRelationsCacheRecord $baseRecord): array|false
    {
        if (!($field instanceof BaseRelationField)) {
            return false;
        }

        /** @var ElementQuery<array-key, ElementInterface> $query */
        $query = $element->{$field->handle}->status(null);
        // ids only: the field's query is for the one site its targets are related in (the element's, or the field's
        // target site), so nothing needs to be loaded
        $targetSiteId = is_numeric($query->siteId) ? (int)$query->siteId : (int)$element->siteId;
        $ids = (clone $query)->drafts(null)->ids(); // targets and their (non-provisional) drafts, one query

        return array_map(function (int|string $id) use ($field, $baseRecord, $targetSiteId): ElementRelationsCacheRecord {
            $record = clone $baseRecord;
            $record->setAttributes([
                'type' => ElementRelationsCacheRecord::TYPE_FIELD,
                'targetElementId' => (int)$id,
                'targetSiteId' => $targetSiteId,
                'customFieldUid' => $field->uid,
                'fieldId' => $field->id,
            ]);
            return $record;
        }, $ids);
    }

    public static function replace(Field $field, ElementInterface $element, ElementInterface $from, ElementInterface $to): ?bool
    {
        if (!($field instanceof BaseRelationField)) {
            return null;
        }
        /** @var ElementQueryInterface $query a relation field's value */
        $query = $element->getFieldValue($field->handle);
        $ids = array_map(intval(...), $query->status(null)->ids());
        if (!in_array($from->id, $ids, true)) {
            return false;
        }
        $element->setFieldValue($field->handle, array_values(array_unique(array_map(fn(int $id): ?int => $id === $from->id ? $to->id : $id, $ids))));
        return true;
    }
}
