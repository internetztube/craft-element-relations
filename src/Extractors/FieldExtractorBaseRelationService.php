<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Extractors;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Queries\ElementQuery;
use CraftCms\Cms\Field\BaseRelationField;
use CraftCms\Cms\Field\Field;
use Internetztube\ElementRelations\Models\CacheRecord;

/** Craft's relation fields: Entries, Assets, Users (and Categories, Tags where the Yii 2 adapter provides them). */
class FieldExtractorBaseRelationService implements InterfaceFieldExtractor, InterfaceFieldReplacer
{
    /** @return list<CacheRecord>|false */
    public static function getRelations(Field $field, ElementInterface $element, CacheRecord $baseRecord): array|false
    {
        if (!$field instanceof BaseRelationField) {
            return false;
        }
        $query = $element->getFieldValue((string)$field->handle);
        if (!$query instanceof ElementQuery) {
            return false;
        }
        // ids only: the field's query is for the one site its targets are related in (the element's, or the field's
        // target site), so nothing needs to be loaded
        $targetSiteId = is_numeric($query->siteId) ? (int)$query->siteId : (int)$element->siteId;
        $ids = (clone $query)->status(null)->drafts(null)->ids(); // targets and their (non-provisional) drafts, one query

        return array_values(array_map(fn(int|string $id): CacheRecord => (clone $baseRecord)->fill([
            'type' => CacheRecord::TYPE_FIELD,
            'targetElementId' => (int)$id,
            'targetSiteId' => $targetSiteId,
            'customFieldUid' => $field->uid,
            'fieldId' => (int)$field->id,
        ]), $ids));
    }

    public static function replace(Field $field, ElementInterface $element, ElementInterface $from, ElementInterface $to): ?bool
    {
        if (!$field instanceof BaseRelationField) {
            return null;
        }
        $ids = array_map(intval(...), $field->getRelationTargetIds($element));
        if (!in_array((int)$from->id, $ids, true)) {
            return false;
        }
        $element->setFieldValue((string)$field->handle, array_values(array_unique(array_map(fn(int $id): int => $id === (int)$from->id ? (int)$to->id : $id, $ids))));
        return true;
    }
}
