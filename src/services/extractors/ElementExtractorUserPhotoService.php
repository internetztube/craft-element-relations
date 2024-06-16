<?php

namespace internetztube\elementRelations\services\extractors;

use craft\base\ElementInterface;
use craft\elements\User;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

class ElementExtractorUserPhotoService implements InterfaceElementExtractor
{
    /**
     * @param ElementInterface $element
     * @param ElementRelationsCacheRecord $baseRecord
     * @return ElementRelationsCacheRecord[]|false
     */
    public static function getRelations(ElementInterface $element, ElementRelationsCacheRecord $baseRecord): ElementRelationsCacheRecord|false
    {
        if (!($element instanceof User) || (!$userPhoto = $element->photo)) {
            return false;
        }

        $record = clone $baseRecord;
        $record->setAttributes([
            'type' => ElementRelationsCacheRecord::TYPE_ELEMENT_USER_PHOTO,
            'targetElementId' => $userPhoto->id,
            'targetSiteId' => $userPhoto->siteId,
        ]);
        return $record;
    }
}