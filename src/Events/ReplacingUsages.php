<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Events;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Shared\Concerns\ValidatableEvent;

/** Before a replacement starts. Set `$isValid = false` to stop it. */
class ReplacingUsages
{
    use ValidatableEvent;

    /** @param list<int>|null $sourceIds the owner ids the replacement is limited to; null: everywhere */
    public function __construct(
        public ElementInterface $from,
        public ElementInterface $to,
        public ?array $sourceIds = null,
    ) {}
}
