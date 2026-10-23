<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Events;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use Internetztube\ElementRelations\Models\Replacement;

/**
 * After a replacement was rolled back. `$from` and `$to` are null when the element is gone by now.
 * @phpstan-import-type RollbackResult from \Internetztube\ElementRelations\Services\ReplaceService
 */
class ReplacementRolledBack
{
    /** @param RollbackResult $result */
    public function __construct(
        public ?ElementInterface $from,
        public ?ElementInterface $to,
        public array $result,
        public Replacement $operation,
    ) {}
}
