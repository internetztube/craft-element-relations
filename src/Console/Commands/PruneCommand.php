<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use Internetztube\ElementRelations\Services\PruneService;

/**
 * Removes cache rows nothing can read: their element, owner or target is gone, or the owner is a revision. Every
 * garbage collection does this too.
 */
class PruneCommand extends Command
{
    use CraftCommand;

    protected $signature = 'element-relations:prune';

    protected $description = 'Removes cache rows nothing can read (their elements are gone).';

    /** @var list<string> */
    protected $aliases = ['element-relations/prune'];

    public function handle(): int
    {
        PruneService::prune(fn(string $line) => $this->line($line));
        return self::SUCCESS;
    }
}
