<?php

namespace internetztube\elementRelations\services\extractors;

use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\RelationalFieldInterface;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

/**
 * Any field that stores its targets in Craft's `relations` table (Craft 5.3+: core relation fields, the core Link
 * field, and every plugin field implementing RelationalFieldInterface). Uses the same target ids Craft writes to that
 * table, so plugin fields are covered without a dedicated extractor. `instanceof` on the interface is safe on Craft 4.
 */
class FieldExtractorRelationalFieldService implements InterfaceFieldExtractor
{
    /**
     * @return ElementRelationsCacheRecord[]|false
     */
    public static function getRelations(Field $field, ElementInterface $element, ElementRelationsCacheRecord $baseRecord): array|false
    {
        if (!($field instanceof RelationalFieldInterface)) {
            return false;
        }

        $records = [];
        foreach (array_unique(array_filter($field->getRelationTargetIds($element))) as $targetId) {
            $record = clone $baseRecord;
            $record->setAttributes([
                'type' => ElementRelationsCacheRecord::TYPE_FIELD,
                'targetElementId' => (int)$targetId,
                // the relations table has no target site; the source element's site is the closest match
                'targetSiteId' => $element->siteId,
                'customFieldUid' => $field->uid,
                'fieldId' => $field->id,
            ]);
            $records[] = $record;
        }
        return $records ?: false;
    }
}
