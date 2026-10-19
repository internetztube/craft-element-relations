<?php

namespace internetztube\elementRelations\services\extractors;

use Craft;
use craft\base\ElementInterface;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;
use verbb\wishlist\elements\Item;

/**
 * verbb/wishlist: an item stores the wished-for element (elementId, elementSiteId, elementClass).
 * The relation is attributed to the item's list: an item's title is the element's own title.
 */
class ElementExtractorWishlistItemService implements InterfaceElementExtractor, InterfaceElementReplacer
{
    /**
     * @return ElementRelationsCacheRecord|false
     */
    public static function getRelations(ElementInterface $element, ElementRelationsCacheRecord $baseRecord): ElementRelationsCacheRecord|array|false
    {
        if (
            !($element instanceof Item)
            || !Craft::$app->getPlugins()->isPluginEnabled('wishlist')
            || !($target = $element->getElement())
        ) {
            return false;
        }

        $record = clone $baseRecord;
        $record->setAttributes([
            'type' => ElementRelationsCacheRecord::TYPE_FIELD,
            'targetElementId' => $target->id,
            'targetSiteId' => $target->siteId,
        ]);
        return $record;
    }

    public static function replace(ElementInterface $element, ElementInterface $from, ElementInterface $to): ?bool
    {
        if (!($element instanceof Item)) {
            return null;
        }
        if ((int)$element->elementId !== $from->id) {
            return false;
        }
        $element->elementId = $to->id;
        $element->elementSiteId = $to->siteId;
        $element->elementClass = $to::class;
        $element->setElement($to);
        return true;
    }
}
