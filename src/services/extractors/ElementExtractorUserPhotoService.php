<?php

declare(strict_types=1);

namespace internetztube\elementRelations\services\extractors;

use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\User;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

class ElementExtractorUserPhotoService implements InterfaceElementExtractor, InterfaceElementReplacer
{
    public static function elementTypes(): array
    {
        return [User::class];
    }

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

    public static function replace(ElementInterface $element, ElementInterface $from, ElementInterface $to): ?bool
    {
        if (!($element instanceof User)) {
            return null;
        }
        if (!($to instanceof Asset) || (int)$element->photoId !== $from->id) {
            return false;
        }
        $element->setPhoto($to);
        return true;
    }
}
