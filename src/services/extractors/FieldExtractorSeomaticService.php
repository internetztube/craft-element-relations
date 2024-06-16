<?php

namespace internetztube\elementRelations\services\extractors;

use craft\base\ElementInterface;
use craft\base\Field;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;
use Craft;

class FieldExtractorSeomaticService implements InterfaceFieldExtractor
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
        $assetIds = $value->metaBundleSettings->seoImageIds;

        if (!$assetIds) {
            return false;
        }

        return collect($assetIds)
            ->map(fn (string $assetId) => (int) $assetId)
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
}