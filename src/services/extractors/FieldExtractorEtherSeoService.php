<?php

namespace internetztube\elementRelations\services\extractors;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\Asset;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

/**
 * SEO by Ether (ether/seo, handle `seo`; not SEOmatic). The value is SeoData; each network in `social`
 * (facebook, twitter) is a SocialData whose `imageId` is the share image's asset id.
 */
class FieldExtractorEtherSeoService implements InterfaceFieldExtractor
{
    /**
     * @param Field $field
     * @param ElementInterface $element
     * @param ElementRelationsCacheRecord $baseRecord
     * @return ElementRelationsCacheRecord[]|false
     */
    public static function getRelations(Field $field, ElementInterface $element, ElementRelationsCacheRecord $baseRecord): array|false
    {
        if (!Craft::$app->plugins->isPluginEnabled('seo') || !($field instanceof \ether\seo\fields\SeoField)) {
            return false;
        }

        $value = $element->{$field->handle};
        if (!($value instanceof \ether\seo\models\data\SeoData)) {
            return false;
        }

        $assetIds = [];
        foreach ((array)$value->social as $social) {
            $imageId = $social instanceof \ether\seo\models\data\SocialData ? $social->imageId : null;
            // The CP posts a plain id; older data holds an id list or `[['id' => …]]` (see SocialData::getImage()).
            if (is_array($imageId)) {
                $imageId = $imageId[0]['id'] ?? $imageId[0] ?? null;
            }
            if (is_numeric($imageId) && (int)$imageId > 0) {
                $assetIds[] = (int)$imageId;
            }
        }
        if (!$assetIds) {
            return [];
        }

        $records = [];
        foreach (Asset::find()->id(array_unique($assetIds))->siteId($element->siteId)->status(null)->ids() as $assetId) {
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
}
