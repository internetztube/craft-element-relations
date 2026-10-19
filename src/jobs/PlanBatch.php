<?php

declare(strict_types=1);

namespace internetztube\elementRelations\jobs;

use craft\base\Batchable;

/**
 * The owner groups of a replacement or rollback for Craft's BaseBatchedJob. The plan is fixed when the operation starts
 * (the cache rows it was made from change while it runs), so every batch sees the same list.
 */
final class PlanBatch implements Batchable
{
    /** @param list<mixed> $groups */
    public function __construct(private readonly array $groups)
    {
    }

    public function count(): int
    {
        return count($this->groups);
    }

    /** @return list<mixed> */
    public function getSlice(int $offset, int $limit): iterable
    {
        return array_slice($this->groups, $offset, $limit);
    }
}
