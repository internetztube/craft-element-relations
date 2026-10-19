<?php

namespace internetztube\elementRelations\services\extractors;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

class FieldExtractorHyperService implements InterfaceFieldExtractor, InterfaceFieldReplacer
{
    /**
     * @param Field $field
     * @param ElementInterface $element
     * @param ElementRelationsCacheRecord $baseRecord
     * @return ElementRelationsCacheRecord[]|false
     */
    public static function getRelations(Field $field, ElementInterface $element, ElementRelationsCacheRecord $baseRecord): array|false
    {
        if (!Craft::$app->plugins->isPluginEnabled('hyper') || !($field instanceof \verbb\hyper\fields\HyperField)) {
            return false;
        }

        /** @var \verbb\hyper\models\LinkCollection $value */
        $value = $element->{$field->handle};
        $links = $value->getLinks();

        return collect($links)
            ->filter(fn (\verbb\hyper\base\Link $link) => $link instanceof \verbb\hyper\base\ElementLink)
            ->map(function (\verbb\hyper\base\ElementLink $link) use ($field, $baseRecord) {
                return collect($link->getElements())
                    ->map(function (ElementInterface $element) use ($field, $baseRecord) {
                        $record = clone $baseRecord;
                        $record->setAttributes([
                            'type' => ElementRelationsCacheRecord::TYPE_FIELD,
                            'targetElementId' => $element->id,
                            'targetSiteId' => $element->siteId,
                            'customFieldUid' => $field->uid,
                            'fieldId' => $field->id,
                        ]);
                        return $record;
                    });
            })
            ->flatten()
            ->filter()
            ->all();
    }

    /**
     * Serialized links: [{type: verbb\hyper\links\Entry, handle, linkValue: id (or [id] as the element select posts it),
     * linkText, newWindow, fields, ...}]. Only the id of the matching element link changes; everything else round-trips.
     */
    public static function replace(Field $field, ElementInterface $element, ElementInterface $from, ElementInterface $to): ?bool
    {
        if (!($field instanceof \verbb\hyper\fields\HyperField)) {
            return null;
        }
        $value = (array)$field->serializeValue($element->getFieldValue($field->handle), $element);
        $count = 0;
        foreach ($value as &$link) {
            $type = $link['type'] ?? null;
            if (!is_subclass_of($type, \verbb\hyper\base\ElementLink::class) || !is_a($from, $type::elementType())) {
                continue;
            }
            if (in_array($from->id, array_map('intval', (array)($link['linkValue'] ?? [])), true)) {
                $link['linkValue'] = $to->id;
                $count++;
            }
        }
        unset($link);
        if (!$count) {
            return false;
        }
        $element->setFieldValue($field->handle, $value);
        return true;
    }
}
