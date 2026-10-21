<?php

namespace internetztube\elementRelations\services\extractors;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

/**
 * FreeLink (justinholtweb/craft-freelink). The value is a LinkCollection (also in single-link mode); element links
 * keep their target in the plugin's `freelink_links` table, which normalizeValue() reads back into targetId/targetSiteId.
 */
class FieldExtractorFreeLinkService implements InterfaceFieldExtractor
{
    /**
     * @param Field $field
     * @param ElementInterface $element
     * @param ElementRelationsCacheRecord $baseRecord
     * @return ElementRelationsCacheRecord[]|false
     */
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
}
