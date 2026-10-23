<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Extractors;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\User\Elements\User;
use Internetztube\ElementRelations\Models\CacheRecord;

class ElementExtractorUserPhotoService implements InterfaceElementExtractor, InterfaceElementReplacer
{
    public static function elementTypes(): array
    {
        return [User::class];
    }

    public static function getRelations(ElementInterface $element, CacheRecord $baseRecord): CacheRecord|false
    {
        if (!$element instanceof User || !$photo = $element->getPhoto()) {
            return false;
        }
        return (clone $baseRecord)->fill([
            'type' => CacheRecord::TYPE_ELEMENT_USER_PHOTO,
            'targetElementId' => (int)$photo->id,
            'targetSiteId' => (int)$photo->siteId,
        ]);
    }

    public static function replace(ElementInterface $element, ElementInterface $from, ElementInterface $to): ?bool
    {
        if (!$element instanceof User) {
            return null;
        }
        if (!$to instanceof Asset || (int)$element->photoId !== (int)$from->id) {
            return false;
        }
        $element->setPhoto($to);
        return true;
    }
}
