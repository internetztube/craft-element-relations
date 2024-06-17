<?php

namespace internetztube\elementRelations\services\extractors;

use craft\base\ElementInterface;
use craft\base\Field;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;
use Craft;

class FieldExtractorTypedLinkFieldService implements InterfaceFieldExtractor
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

        /** @var \lenz\linkfield\models\Link $value */
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
}