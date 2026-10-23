<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Events;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use Internetztube\ElementRelations\Models\Replacement;

/**
 * After a replacement ran.
 * @phpstan-import-type ReplaceResult from \Internetztube\ElementRelations\Services\ReplaceService
 */
class UsagesReplaced
{
    /**
     * @param list<int>|null $sourceIds
     * @param ReplaceResult $result
     */
    public function __construct(
        public ElementInterface $from,
        public ElementInterface $to,
        public ?array $sourceIds,
        public array $result,
        public Replacement $operation,
    ) {}
}
