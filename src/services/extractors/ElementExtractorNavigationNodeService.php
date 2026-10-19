<?php

namespace internetztube\elementRelations\services\extractors;

use Craft;
use craft\base\ElementInterface;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;
use verbb\navigation\elements\Node;

/**
 * verbb/navigation 3+: a node of an element type (Entry, Asset, Category, Product, ...) links that element.
 * The relation is attributed to the node's menu by ExtractorService::getPrimaryOwnerId() (Navigation 4: node.menuId),
 * because a node's title defaults to the linked element's title, so "Page C" would be shown as used by "Page C".
 */
class ElementExtractorNavigationNodeService implements InterfaceElementExtractor, InterfaceElementReplacer
{
    /**
     * @return ElementRelationsCacheRecord|false
     */
    public static function getRelations(ElementInterface $element, ElementRelationsCacheRecord $baseRecord): ElementRelationsCacheRecord|array|false
    {
        if (
            !($element instanceof Node)
            || !Craft::$app->getPlugins()->isPluginEnabled('navigation')
            // A draft node (staged builder change) would otherwise show up as its live menu.
            || $element->getIsDraft()
            || !($linked = $element->getElement())
        ) {
            return false;
        }

        $record = clone $baseRecord;
        $record->setAttributes([
            'type' => ElementRelationsCacheRecord::TYPE_FIELD,
            'targetElementId' => $linked->id,
            'targetSiteId' => $linked->siteId,
        ]);
        // Navigation 3 menus are not elements; keep the node itself as the source there.
        return $record;
    }

    public static function replace(ElementInterface $element, ElementInterface $from, ElementInterface $to): ?bool
    {
        if (!($element instanceof Node)) {
            return null;
        }
        if ((int)$element->elementId !== $from->id) {
            return false;
        }
        $element->elementId = $to->id;
        $element->setElementSiteId($to->siteId); // persisted by Navigation's node site settings on save
        $element->setElement($to);
        return true;
    }
}
