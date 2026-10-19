<?php

declare(strict_types=1);

namespace internetztube\elementRelations\services\extractors;

use craft\base\ElementInterface;
use craft\base\Field;

/**
 * The inverse of InterfaceFieldExtractor: every field extractor that reads references out of a field value also
 * knows how to swap one reference for another in that value. Implemented on the extractor class itself, so reading
 * and writing a field type live together.
 */
interface InterfaceFieldReplacer
{
    /**
     * Swaps every reference to `$from` in the element's value of this field for `$to`, in memory via
     * `$element->setFieldValue()`; the caller saves the element.
     *
     * @return bool|null true = value changed; false = this field holds no reference to `$from`;
     *                   null = not this replacer's field type (the next one is asked)
     */
    public static function replace(Field $field, ElementInterface $element, ElementInterface $from, ElementInterface $to): ?bool;
}
