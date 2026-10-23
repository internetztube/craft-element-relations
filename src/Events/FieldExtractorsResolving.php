<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Events;

use Internetztube\ElementRelations\Extractors\InterfaceFieldExtractor;

/**
 * Lets plugins add (or remove) field extractors: class names implementing InterfaceFieldExtractor, ideally
 * InterfaceFieldReplacer as well. Order matters: the first extractor that returns relations for a field wins, so add
 * specific ones before the generic ones.
 *
 * ```php
 * Event::listen(function (FieldExtractorsResolving $event): void {
 *     array_unshift($event->types, MyFieldExtractor::class);
 * });
 * ```
 */
class FieldExtractorsResolving
{
    /** @param list<class-string<InterfaceFieldExtractor>> $types */
    public function __construct(public array $types) {}
}
