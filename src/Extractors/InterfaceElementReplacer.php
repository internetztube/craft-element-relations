<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Extractors;

use CraftCms\Cms\Element\Contracts\ElementInterface;

/**
 * The inverse of InterfaceElementExtractor for usages stored outside custom fields (a user's photo): swap the
 * reference on the element in memory; the caller saves it.
 */
interface InterfaceElementReplacer
{
    /**
     * @return bool|null true = element changed; false = the element does not reference `$from` this way;
     *                   null = not this replacer's element type
     */
    public static function replace(ElementInterface $element, ElementInterface $from, ElementInterface $to): ?bool;
}
