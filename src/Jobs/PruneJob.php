<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Jobs;

use CraftCms\Cms\Queue\Job;
use Internetztube\ElementRelations\Services\PruneService;
use Override;

/** Removes the cache rows of elements garbage collection deleted; batched deletes, no table lock (see PruneService). */
class PruneJob extends Job
{
    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): void
    {
        PruneService::prune(rebuild: false);
    }

    #[Override]
    protected function defaultDescription(): string
    {
        return 'Prune Element Relations';
    }
}
