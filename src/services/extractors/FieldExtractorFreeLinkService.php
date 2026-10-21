<?php

declare(strict_types=1);

namespace internetztube\elementRelations\services\extractors;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

/**
 * FreeLink (justinholtweb/craft-freelink). The value is a LinkCollection (also in single-link mode); element links
 * keep their target in the plugin's `freelink_links` table, which normalizeValue() reads back into targetId/targetSiteId.
 */
class FieldExtractorFreeLinkService implements InterfaceFieldExtractor, InterfaceFieldReplacer
{
    /** @return list<ElementRelationsCacheRecord>|false */
    public static function getRelations(Field $field, ElementInterface $element, ElementRelationsCacheRecord $baseRecord): array|false
    {
        if (!Craft::$app->plugins->isPluginEnabled('freelink') || !($field instanceof \justinholtweb\freelink\fields\FreeLinkField)) {
            return false;
        }

        $value = $element->{$field->handle};
        if (!($value instanceof \justinholtweb\freelink\models\LinkCollection)) {
            return false;
        }

        $records = [];
        foreach ($value->getAll() as $link) {
            if (!($link instanceof \justinholtweb\freelink\base\ElementLink) || !$linkElement = $link->getElement()) {
                continue;
            }
            $record = clone $baseRecord;
            $record->setAttributes([
                'type' => ElementRelationsCacheRecord::TYPE_FIELD,
                'targetElementId' => $linkElement->id,
                'targetSiteId' => $linkElement->siteId,
                'customFieldUid' => $field->uid,
                'fieldId' => $field->id,
            ]);
            $records[] = $record;
        }
        return $records;
    }

    /**
     * The links are changed in memory: normalizeValue() takes element targets from the `freelink_links` table unless
     * the data looks like a control panel post, so a serialized array with a new targetId would be reverted on save.
     * afterElementSave() then rewrites that table from the collection.
     */
    public static function replace(Field $field, ElementInterface $element, ElementInterface $from, ElementInterface $to): ?bool
    {
        if (!($field instanceof \justinholtweb\freelink\fields\FreeLinkField)) {
            return null;
        }
        $value = $element->getFieldValue($field->handle);
        if (!($value instanceof \justinholtweb\freelink\models\LinkCollection)) {
            return false;
        }
        $count = 0;
        foreach ($value->getAll() as $link) {
            if ($link instanceof \justinholtweb\freelink\base\ElementLink && $link->targetId === $from->id && is_a($from, $link::elementType())) {
                $link->setElement($to);
                $count++;
            }
        }
        if (!$count) {
            return false;
        }
        $element->setFieldValue($field->handle, $value);
        return true;
    }
}
