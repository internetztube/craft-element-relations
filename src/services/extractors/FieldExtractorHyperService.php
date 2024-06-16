<?php

namespace internetztube\elementRelations\services\extractors;

use craft\base\ElementInterface;
use craft\base\Field;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;
use verbb\hyper\base\ElementLink;
use Craft;

class FieldExtractorHyperService implements InterfaceFieldExtractor
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
            ->filter(fn (\verbb\hyper\base\Link $link) => $link instanceof ElementLink)
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
}