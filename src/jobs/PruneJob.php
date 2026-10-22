<?php

declare(strict_types=1);

namespace internetztube\elementRelations\jobs;

use craft\queue\BaseJob;
use internetztube\elementRelations\services\PruneService;

/** Removes the cache rows of elements garbage collection deleted; batched deletes, no table lock (see PruneService). */
class PruneJob extends BaseJob
{
    public function execute($queue): void
    {
        PruneService::prune(rebuild: false);
    }

    protected function defaultDescription(): ?string
    {
        return 'Prune Element Relations';
    }
}
