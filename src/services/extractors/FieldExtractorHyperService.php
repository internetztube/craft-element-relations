<?php

declare(strict_types=1);

namespace internetztube\elementRelations\services\extractors;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

class FieldExtractorHyperService implements InterfaceFieldExtractor, InterfaceFieldReplacer
{
    /** @return list<ElementRelationsCacheRecord>|false */
    public static function getRelations(Field $field, ElementInterface $element, ElementRelationsCacheRecord $baseRecord): array|false
    {
        if (!Craft::$app->plugins->isPluginEnabled('hyper') || !($field instanceof \verbb\hyper\fields\HyperField)) {
            return false;
        }

        /** @var \verbb\hyper\models\LinkCollection $value */
        $value = $element->{$field->handle};

        $records = [];
        foreach ($value->getLinks() as $link) {
            if (!$link instanceof \verbb\hyper\base\ElementLink) {
                continue;
            }
            /** @var ElementInterface $linkElement */
            foreach ($link->getElements() as $linkElement) {
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
        }
        return $records;
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
        /** @var list<array{type?: string, linkValue?: int|string|list<int|string>}> $value */
        $value = (array)$field->serializeValue($element->getFieldValue($field->handle), $element);
        $count = 0;
        foreach ($value as &$link) {
            $type = $link['type'] ?? null;
            if ($type === null || !is_subclass_of($type, \verbb\hyper\base\ElementLink::class) || !is_a($from, $type::elementType())) {
                continue;
            }
            if (in_array($from->id, array_map(intval(...), (array)($link['linkValue'] ?? [])), true)) {
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
