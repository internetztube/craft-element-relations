<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Jobs;

use CraftCms\Cms\Queue\Job;
use CraftCms\Cms\Support\PHP;
use Illuminate\Queue\Jobs\SyncJob;

/**
 * Works through a fixed plan (the owner groups of a replacement or rollback, kept in the operation record) in batches:
 * stops a batch before the queue's time limit or the memory limit, then queues itself again from where it got. Like
 * Craft's BatchedJob, which needs a database query to page through.
 */
abstract class PlanJob extends Job
{
    /** Owner groups done so far (in earlier batches). */
    public int $itemOffset = 0;

    /** @return list<mixed> the plan */
    abstract protected function items(): array;

    abstract protected function processItem(mixed $item): void;

    protected function after(): void {}

    protected function afterBatch(): void {}

    /** Runs the batch from `itemOffset`; false when it stopped early and queued the rest. */
    protected function runBatch(): bool
    {
        $items = $this->items();
        $total = count($items);
        $memoryLimit = PHP::sizeToBytes((string)ini_get('memory_limit'));
        $startMemory = memory_get_usage();
        $start = microtime(true);
        $done = 0;
        while ($this->itemOffset < $total) {
            $this->processItem($items[$this->itemOffset]);
            $this->itemOffset++;
            $done++;
            $this->setProgress((int)floor($this->itemOffset / $total * 100), "$this->itemOffset/$total");
            $memory = memory_get_usage();
            $running = microtime(true) - $start;
            // stop with room for two more of the slowest kind
            if (($memoryLimit !== -1 && $memory + ($memory - $startMemory) / $done * 15 > $memoryLimit) || $running + $running / $done * 2 > $this->timeout) {
                break;
            }
        }
        $this->afterBatch();
        if ($this->itemOffset < $total) {
            $next = clone $this;
            if ($this->job instanceof SyncJob) {
                $next->handle();
            } else {
                dispatch($next);
            }
            return false;
        }
        $this->after();
        return true;
    }
}
