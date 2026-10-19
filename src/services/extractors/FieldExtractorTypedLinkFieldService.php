<?php

namespace internetztube\elementRelations\services\extractors;

use craft\base\ElementInterface;
use craft\base\Field;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;
use Craft;

class FieldExtractorTypedLinkFieldService implements InterfaceFieldExtractor, InterfaceFieldReplacer
{
    /**
     * @param Field $field
     * @param ElementInterface $element
     * @param ElementRelationsCacheRecord $baseRecord
     * @return ElementRelationsCacheRecord[]|false
     */
    public static function getRelations(Field $field, ElementInterface $element, ElementRelationsCacheRecord $baseRecord): array|false
    {
        if (!Craft::$app->plugins->isPluginEnabled('typedlinkfield') || !($field instanceof \lenz\linkfield\fields\LinkField)) {
            return false;
        }

        /** @var \lenz\linkfield\models\Link $link */
        $link = $element->{$field->handle};

        if (!($link instanceof \lenz\linkfield\models\element\ElementLink)) {
            return false;
        }

        $linkElement = $link->getElement(true);
        if (!$linkElement) {
            return false;
        }

        $record = clone $baseRecord;
        $record->setAttributes([
            'type' => ElementRelationsCacheRecord::TYPE_FIELD,
            'targetElementId' => $linkElement->id,
            'targetSiteId' => $linkElement->siteId,
            'customFieldUid' => $field->uid,
            'fieldId' => $field->id,
        ]);
        return [$record];
    }

    /**
     * Serialized value: JSON of the record attributes (type, linkedId, linkedSiteId, linkedTitle, linkedUrl, payload).
     * The cached title/URL are dropped so the plugin recomputes them for the new element.
     */
    public static function replace(Field $field, ElementInterface $element, ElementInterface $from, ElementInterface $to): ?bool
    {
        if (!Craft::$app->plugins->isPluginEnabled('typedlinkfield') || !($field instanceof \lenz\linkfield\fields\LinkField)) {
            return null;
        }
        $link = $element->getFieldValue($field->handle);
        if (!($link instanceof \lenz\linkfield\models\element\ElementLink) || $link->linkedId !== $from->id) {
            return false;
        }
        $value = \craft\helpers\Json::decode($field->serializeValue($link, $element));
        unset($value['linkedTitle'], $value['linkedUrl']);
        $element->setFieldValue($field->handle, ['linkedId' => $to->id] + $value);
        return true;
    }
}
