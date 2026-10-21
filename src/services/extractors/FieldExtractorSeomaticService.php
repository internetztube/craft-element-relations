<?php

declare(strict_types=1);

namespace internetztube\elementRelations\services\extractors;

use craft\base\ElementInterface;
use craft\base\Field;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

class FieldExtractorSeomaticService implements InterfaceFieldExtractor, InterfaceFieldReplacer
{
    /** @return list<ElementRelationsCacheRecord>|false */
    public static function getRelations(Field $field, ElementInterface $element, ElementRelationsCacheRecord $baseRecord): array|false
    {
        // a disabled plugin's fields are Craft's MissingField, so the type check covers that too
        if (!($field instanceof \nystudio107\seomatic\fields\SeoSettings)) {
            return false;
        }

        /** @var \nystudio107\seomatic\models\MetaBundle $value */
        $value = $element->{$field->handle};
        $settings = $value->metaBundleSettings;
        // SEO, Twitter and Open Graph images are separate asset lists
        /** @var list<int|string> $ids */
        $ids = [...(array)$settings->seoImageIds, ...(array)$settings->twitterImageIds, ...(array)$settings->ogImageIds];
        $assetIds = array_unique(array_filter(array_map(intval(...), $ids)));
        if (!$assetIds) {
            return false;
        }

        $records = [];
        foreach ($assetIds as $assetId) {
            $record = clone $baseRecord;
            $record->setAttributes([
                'type' => ElementRelationsCacheRecord::TYPE_FIELD,
                'targetElementId' => $assetId,
                'targetSiteId' => $element->siteId,
                'customFieldUid' => $field->uid,
                'fieldId' => $field->id,
            ]);
            $records[] = $record;
        }
        return $records;
    }

    public static function replace(Field $field, ElementInterface $element, ElementInterface $from, ElementInterface $to): ?bool
    {
        if (!($field instanceof \nystudio107\seomatic\fields\SeoSettings)) {
            return null;
        }
        /** @var array{metaBundleSettings?: array<string, int|string|list<int|string>>} $value */
        $value = $field->serializeValue($element->getFieldValue($field->handle), $element);
        $count = 0;
        foreach (['seoImageIds', 'twitterImageIds', 'ogImageIds'] as $key) {
            $ids = (array)($value['metaBundleSettings'][$key] ?? []);
            $value['metaBundleSettings'][$key] = array_values(array_unique(array_map(function (mixed $id) use ($from, $to, &$count): mixed {
                if ((int)$id === $from->id) {
                    $count++;
                    return (string)$to->id;
                }
                return $id;
            }, $ids)));
        }
        if (!$count) {
            return false;
        }
        $element->setFieldValue($field->handle, $value);
        return true;
    }
}
