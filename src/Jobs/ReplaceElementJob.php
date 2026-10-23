<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Jobs;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\User\Elements\User;
use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Models\Replacement;
use Internetztube\ElementRelations\Services\ReplaceRun;
use Internetztube\ElementRelations\Support\ActingUser;
use Override;

use function CraftCms\Cms\t;

/**
 * Runs a replacement started in the control panel, as the user who started it, in batches of owner groups (an element
 * may have tens of thousands of usages; the job queues itself again before the queue's time limit).
 */
class ReplaceElementJob extends PlanJob
{
    private ?Replacement $operation = null;
    private ?ReplaceRun $run = null;

    public function __construct(public int $operationId, int $itemOffset = 0)
    {
        parent::__construct();
        $this->itemOffset = $itemOffset;
    }

    public function handle(): void
    {
        $operation = Replacement::find($this->operationId);
        if (!$operation instanceof Replacement || $operation->status !== Replacement::STATUS_QUEUED) {
            return;
        }
        $user = null;
        if ($operation->userId) {
            // run as the user who started it, if they still exist and may still replace; otherwise do nothing
            $user = User::find()->id($operation->userId)->status(null)->one();
            if (!$user instanceof User || !ElementRelations::canReplace($user)) {
                $operation->status = Replacement::STATUS_FAILED;
                $operation->result = json_encode(['replaced' => 0, 'skipped' => [t('replace-job-user-gone', category: 'element-relations')]]) ?: null;
                $operation->save();
                ElementRelations::log()->warning(sprintf('Replacement #%d not run: user #%d is gone or may no longer replace', $operation->id, $operation->userId));
                return;
            }
        }
        ActingUser::run($user, function () use ($operation): void {
            try {
                if ($this->prepare($operation)) {
                    $this->runBatch();
                }
            } catch (\Throwable $e) {
                $this->stop($operation, $e);
                throw $e;
            }
        });
    }

    /** Sets up the run; false when it must not run (and is marked so). */
    private function prepare(Replacement $operation): bool
    {
        $from = Elements::getElementById($operation->fromId, null, $operation->siteId);
        $to = $from ? Elements::getElementById($operation->toId, $from::class, $operation->siteId) : null;
        if (!$from instanceof ElementInterface || !$to instanceof ElementInterface) {
            $operation->status = Replacement::STATUS_APPLIED;
            $operation->result = json_encode(['replaced' => 0, 'skipped' => [t('replace-invalid', category: 'element-relations')]]) ?: null;
            $operation->save();
            return false;
        }
        $this->operation = $operation;
        // operations queued before plans existed get theirs now
        $this->run = $operation->plan ? ReplaceRun::resume($operation, $from, $to) : ReplaceRun::plan($operation, $from, $to, self::sourceIds($operation));
        return true;
    }

    /** @return list<mixed> */
    #[Override]
    protected function items(): array
    {
        return $this->operation ? ReplaceRun::groups($this->operation) : [];
    }

    #[Override]
    protected function processItem(mixed $item): void
    {
        /** @var array{ownerId: int, siteId: int, rows: list<array{sourceElementId: int, sourceSiteId: int, sourcePrimaryOwnerId: int|null, fieldId: int|null, type: string}>} $item */
        $this->run?->processGroup($item);
    }

    #[Override]
    protected function afterBatch(): void
    {
        $this->run?->persist();
        gc_collect_cycles(); // a queue worker runs batch after batch in one process: keep its memory flat
    }

    #[Override]
    protected function after(): void
    {
        if ($this->run && $this->operation) {
            $this->run->finish(self::sourceIds($this->operation));
        }
    }

    /** A batch failed: keep what was done and make it undoable instead of leaving it "queued" forever. */
    private function stop(Replacement $operation, \Throwable $e): void
    {
        $this->run?->persist();
        $operation->refresh();
        $result = $operation->getResult();
        $result['skipped'] = [...($result['skipped'] ?? []), t('replace-stopped', ['error' => $e->getMessage()], 'element-relations')];
        $operation->status = Replacement::STATUS_APPLIED;
        $operation->result = json_encode($result) ?: null;
        $operation->save();
        ElementRelations::log()->error(sprintf('Replacement #%d stopped: %s', $operation->id, $e->getMessage()));
    }

    /** @return list<int>|null */
    private static function sourceIds(Replacement $operation): ?array
    {
        if (!$operation->sourceIds) {
            return null;
        }
        $ids = json_decode($operation->sourceIds, true);
        return is_array($ids) ? array_values(array_map(fn(mixed $id): int => is_numeric($id) ? (int)$id : 0, $ids)) : null;
    }

    #[Override]
    protected function defaultDescription(): string
    {
        return t('replace-job-description', category: 'element-relations');
    }

    /** The next batch loads the operation and the run again (they would go stale in the queue). */
    public function __clone(): void
    {
        $this->operation = null;
        $this->run = null;
    }
}
