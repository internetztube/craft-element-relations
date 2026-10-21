<?php

namespace internetztube\elementRelations\services\extractors;

use craft\base\ElementInterface;
use craft\base\Field;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;
use Craft;

class FieldExtractorSeomaticService implements InterfaceFieldExtractor, InterfaceFieldReplacer
{
    /**
     * @param Field $field
     * @param ElementInterface $element
     * @param ElementRelationsCacheRecord $baseRecord
     * @return ElementRelationsCacheRecord[]|false
     */
    public static function getRelations(Field $field, ElementInterface $element, ElementRelationsCacheRecord $baseRecord): array|false
    {
        if (!Craft::$app->plugins->isPluginEnabled('seomatic')) {
            return false;
        }

        if (!($field instanceof \nystudio107\seomatic\fields\SeoSettings)) {
            return false;
        }

        /** @var \nystudio107\seomatic\models\MetaBundle $value */
        $value = $element->{$field->handle};
        $settings = $value->metaBundleSettings;
        // SEO, Twitter and Open Graph images are separate asset lists
        $assetIds = collect([...(array)$settings->seoImageIds, ...(array)$settings->twitterImageIds, ...(array)$settings->ogImageIds])
            ->map(fn ($assetId) => (int) $assetId)
            ->filter()
            ->unique();

        if ($assetIds->isEmpty()) {
            return false;
        }

        return $assetIds
            ->map(function (int $assetId) use ($field, $baseRecord, $element) {
                $record = clone $baseRecord;
                $record->setAttributes([
                    'type' => ElementRelationsCacheRecord::TYPE_FIELD,
                    'targetElementId' => $assetId,
                    'targetSiteId' => $element->siteId,
                    'customFieldUid' => $field->uid,
                    'fieldId' => $field->id,
                ]);
                return $record;
            })
            ->flatten()
            ->filter()
            ->all();
    }

    public static function replace(Field $field, ElementInterface $element, ElementInterface $from, ElementInterface $to): ?bool
    {
        if (!($field instanceof \nystudio107\seomatic\fields\SeoSettings)) {
            return null;
        }
        $value = $field->serializeValue($element->getFieldValue($field->handle), $element);
        $count = 0;
        foreach (['seoImageIds', 'twitterImageIds', 'ogImageIds'] as $key) {
            $ids = (array)($value['metaBundleSettings'][$key] ?? []);
            $value['metaBundleSettings'][$key] = array_values(array_unique(array_map(function ($id) use ($from, $to, &$count) {
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
