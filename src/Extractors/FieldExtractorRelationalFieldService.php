<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Extractors;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Field\Contracts\RelationalFieldInterface;
use CraftCms\Cms\Field\Field;
use Internetztube\ElementRelations\Models\CacheRecord;

/**
 * Any other field that stores its targets in Craft's `relations` table: every plugin field implementing
 * RelationalFieldInterface. Uses the same target ids Craft writes to that table, so such fields are covered without a
 * dedicated extractor (reading only: their values have their own formats).
 */
class FieldExtractorRelationalFieldService implements InterfaceFieldExtractor
{
    /** @return list<CacheRecord>|false */
    public static function getRelations(Field $field, ElementInterface $element, CacheRecord $baseRecord): array|false
    {
        if (!$field instanceof RelationalFieldInterface) {
            return false;
        }
        $records = [];
        foreach (array_unique(array_filter(array_map(intval(...), $field->getRelationTargetIds($element)))) as $targetId) {
            $records[] = (clone $baseRecord)->fill([
                'type' => CacheRecord::TYPE_FIELD,
                'targetElementId' => $targetId,
                // the relations table has no target site; the source element's site is the closest match
                'targetSiteId' => (int)$element->siteId,
                'customFieldUid' => $field->uid,
                'fieldId' => (int)$field->id,
            ]);
        }
        return $records ?: false;
    }
}
