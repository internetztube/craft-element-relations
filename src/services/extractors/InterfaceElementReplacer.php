<?php

declare(strict_types=1);

namespace internetztube\elementRelations\services\extractors;

use craft\base\ElementInterface;

/**
 * The inverse of InterfaceElementExtractor for usages stored outside custom fields (user photo, navigation node,
 * wishlist item, Formie form settings): swap the reference on the element in memory; the caller saves it.
 */
interface InterfaceElementReplacer
{
    /**
     * @return bool|null true = element changed; false = the element does not reference `$from` this way;
     *                   null = not this replacer's element type
     */
    public static function replace(ElementInterface $element, ElementInterface $from, ElementInterface $to): ?bool;
}
