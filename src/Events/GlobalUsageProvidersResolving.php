<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Events;

use Internetztube\ElementRelations\Extractors\InterfaceGlobalUsage;

/** Lets plugins add providers of usages that are not elements (plugin settings), see InterfaceGlobalUsage. */
class GlobalUsageProvidersResolving
{
    /** @param list<class-string<InterfaceGlobalUsage>> $types */
    public function __construct(public array $types) {}
}
