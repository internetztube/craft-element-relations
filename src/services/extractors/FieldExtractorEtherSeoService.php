<?php

declare(strict_types=1);

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
class FieldExtractorEtherSeoService implements InterfaceFieldExtractor, InterfaceFieldReplacer
{
    /** @return list<ElementRelationsCacheRecord>|false */
    public static function getRelations(Field $field, ElementInterface $element, ElementRelationsCacheRecord $baseRecord): array|false
    {
        if (!Craft::$app->plugins->isPluginEnabled('seo') || !($field instanceof \ether\seo\fields\SeoField)) {
            return false;
        }

        /** @var \ether\seo\models\data\SeoData $value the field's normalized value */
        $value = $element->{$field->handle};

        $assetIds = [];
        foreach ((array)$value->social as $social) {
            if ($social instanceof \ether\seo\models\data\SocialData && $imageId = self::imageId($social)) {
                $assetIds[] = $imageId;
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

    /** The CP posts a plain id; older data holds an id list or `[['id' => …]]` (see SocialData::getImage()). */
    private static function imageId(\ether\seo\models\data\SocialData $social): ?int
    {
        $imageId = $social->imageId;
        if (is_array($imageId)) {
            $imageId = $imageId[0]['id'] ?? $imageId[0] ?? null;
        }
        return is_numeric($imageId) && (int)$imageId > 0 ? (int)$imageId : null;
    }

    /** Changes the SocialData objects in place (SeoData is stored as its public properties); the id is written in the plain shape the CP posts. */
    public static function replace(Field $field, ElementInterface $element, ElementInterface $from, ElementInterface $to): ?bool
    {
        if (!($field instanceof \ether\seo\fields\SeoField)) {
            return null;
        }
        $value = $element->getFieldValue($field->handle);
        if (!($from instanceof Asset) || !($value instanceof \ether\seo\models\data\SeoData)) {
            return false;
        }
        $count = 0;
        foreach ((array)$value->social as $social) {
            if ($social instanceof \ether\seo\models\data\SocialData && self::imageId($social) === $from->id) {
                $social->imageId = (string)$to->id;
                $count++;
            }
        }
        if (!$count) {
            return false;
        }
        $element->setFieldValue($field->handle, $value);
        return true;
    }
}
