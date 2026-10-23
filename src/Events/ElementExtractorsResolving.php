<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Events;

use Internetztube\ElementRelations\Extractors\InterfaceElementExtractor;

/** Lets plugins add (or remove) element extractors (InterfaceElementExtractor, ideally InterfaceElementReplacer as well). */
class ElementExtractorsResolving
{
    /** @param list<class-string<InterfaceElementExtractor>> $types */
    public function __construct(public array $types) {}
}
