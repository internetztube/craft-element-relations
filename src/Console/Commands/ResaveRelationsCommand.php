<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use Internetztube\ElementRelations\Jobs\GenerateResaveAllElementRelationsJobsJob;

/** Rebuilds the element relations cache via the queue. */
class ResaveRelationsCommand extends Command
{
    use CraftCommand;

    protected $signature = 'element-relations:resave-relations';

    protected $description = 'Queues a job that refreshes the relations cache of every element.';

    /** @var list<string> */
    protected $aliases = ['element-relations/resave-relations'];

    public function handle(): int
    {
        dispatch(new GenerateResaveAllElementRelationsJobsJob());
        $this->info('Pushed the job into the queue.');
        return self::SUCCESS;
    }
}
