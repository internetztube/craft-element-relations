<?php

namespace internetztube\elementRelations\services\extractors;

use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\RelationalFieldInterface;
use craft\fields\Link;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

/**
 * Any field that stores its targets in Craft's `relations` table (Craft 5.3+: core relation fields, the core Link
 * field, and every plugin field implementing RelationalFieldInterface). Uses the same target ids Craft writes to that
 * table, so plugin fields are covered without a dedicated extractor. `instanceof` on the interface is safe on Craft 4.
 */
class FieldExtractorRelationalFieldService implements InterfaceFieldExtractor, InterfaceFieldReplacer
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

    /**
     * Only the core Link field: its serialized value is ['type' => 'entry', 'value' => '{entry:12@1:url}', ...attributes],
     * so the reference tag's id is swapped. BaseRelationFields have their own replacer; other RelationalFieldInterface
     * fields (plugins) store their value in their own format and are not supported here.
     */
    public static function replace(Field $field, ElementInterface $element, ElementInterface $from, ElementInterface $to): ?bool
    {
        if (!($field instanceof Link)) {
            return null;
        }
        $value = $field->serializeValue($element->getFieldValue($field->handle), $element);
        if (!is_array($value) || !is_string($value['value'] ?? null)) {
            return false;
        }
        $value['value'] = preg_replace(
            '/^\\{' . preg_quote($from::refHandle(), '/') . ':' . $from->id . '(@\\d+)?:url\\}$/',
            '{' . $to::refHandle() . ':' . $to->id . '$1:url}',
            $value['value'],
            -1,
            $count,
        );
        if (!$count) {
            return false;
        }
        $element->setFieldValue($field->handle, $value);
        return true;
    }
}
