<?php

namespace internetztube\elementRelations\services\extractors;

use craft\base\ElementInterface;
use craft\base\Field;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;
use presseddigital\linkit\base\Link;
use Craft;

class FieldExtractorLinkItService implements InterfaceFieldExtractor
{
    /**
     * @param Field $field
     * @param ElementInterface $element
     * @param ElementRelationsCacheRecord $baseRecord
     * @return ElementRelationsCacheRecord[]|false
     */
    public static function getRelations(Field $field, ElementInterface $element, ElementRelationsCacheRecord $baseRecord): array|false
    {
        if (!Craft::$app->plugins->isPluginEnabled('linkit') || !($field instanceof \presseddigital\linkit\fields\LinkitField)) {
            return false;
        }

        /** @var Link $value */
        $value = $element->{$field->handle};

        if (!($value instanceof \presseddigital\linkit\base\ElementLink) || !$linkElement = $value->getElement()) {
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
}