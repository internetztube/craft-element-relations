<?php

declare(strict_types=1);

namespace internetztube\elementRelations\services\extractors;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

/**
 * LinkMate (vaersaagod/linkmate). The value is a single Link; for element link types `value` holds the element id
 * (stored as JSON in the content column).
 */
class FieldExtractorLinkMateService implements InterfaceFieldExtractor, InterfaceFieldReplacer
{
    /** @return list<ElementRelationsCacheRecord>|false */
    public static function getRelations(Field $field, ElementInterface $element, ElementRelationsCacheRecord $baseRecord): array|false
    {
        if (!Craft::$app->plugins->isPluginEnabled('linkmate') || !($field instanceof \vaersaagod\linkmate\fields\LinkField)) {
            return false;
        }

        $link = $element->{$field->handle};
        if (
            !($link instanceof \vaersaagod\linkmate\models\Link)
            || !($link->getLinkType() instanceof \vaersaagod\linkmate\models\ElementLinkType)
            || !$linkElement = $link->getElement(true)
        ) {
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

    /** The Link model's attributes (type, value, customText, target, title, ...) are what normalizeValue() accepts. */
    public static function replace(Field $field, ElementInterface $element, ElementInterface $from, ElementInterface $to): ?bool
    {
        if (!Craft::$app->plugins->isPluginEnabled('linkmate') || !($field instanceof \vaersaagod\linkmate\fields\LinkField)) {
            return null;
        }
        $link = $element->getFieldValue($field->handle);
        if (
            !($link instanceof \vaersaagod\linkmate\models\Link)
            || !($link->getLinkType() instanceof \vaersaagod\linkmate\models\ElementLinkType)
            || (int)$link->value !== $from->id
        ) {
            return false;
        }
        $element->setFieldValue($field->handle, ['value' => $to->id] + $link->getAttributes());
        return true;
    }
}
