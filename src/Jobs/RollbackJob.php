<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Jobs;

use CraftCms\Cms\User\Elements\User;
use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Models\Replacement;
use Internetztube\ElementRelations\Services\ReplaceService;
use Internetztube\ElementRelations\Services\RollbackRun;
use Internetztube\ElementRelations\Support\ActingUser;
use Override;

use function CraftCms\Cms\t;

/**
 * Rolls a replacement back (Undo, Roll back, Revert to revisions in the control panel) as the user who asked, in
 * batches of owner groups like ReplaceElementJob.
 */
class RollbackJob extends PlanJob
{
    private ?Replacement $operation = null;
    private ?RollbackRun $run = null;

    /**
     * @param ReplaceService::ROLLBACK_* $mode
     * @param int|null $userId who asked for the rollback; null in console runs
     */
    public function __construct(public int $operationId, public string $mode = ReplaceService::ROLLBACK_SWAP, public ?int $userId = null, int $itemOffset = 0)
    {
        parent::__construct();
        $this->itemOffset = $itemOffset;
    }

    public function handle(): void
    {
        $operation = Replacement::find($this->operationId);
        if (!$operation instanceof Replacement || $operation->status !== Replacement::STATUS_ROLLBACK_QUEUED) {
            return;
        }
        $user = null;
        if ($this->userId !== null) {
            // as the user who asked, if they still may; otherwise the operation stays as it was
            $user = User::find()->id($this->userId)->status(null)->one();
            if (!$user instanceof User || !ElementRelations::canRollBack($user) || !ElementRelations::canRollBackOperation($operation, $user)) {
                $this->restore($operation, sprintf('user #%d is gone or may no longer roll it back', $this->userId));
                return;
            }
        }
        ActingUser::run($user, function () use ($operation): void {
            try {
                $this->operation = $operation;
                $this->run = RollbackRun::start($operation, $this->mode, $this->userId);
                $this->runBatch();
            } catch (\Throwable $e) {
                $this->run?->persist();
                $this->restore($operation, $e->getMessage());
                throw $e;
            }
        });
    }

    /** @return list<mixed> */
    #[Override]
    protected function items(): array
    {
        return $this->operation ? RollbackRun::groups($this->operation) : [];
    }

    #[Override]
    protected function processItem(mixed $item): void
    {
        /** @var array{key: string, changes: list<array{elementId: int, siteId: int, ownerId?: int, fieldId: int|null, preHadTo: bool, before: mixed, after?: mixed}>} $item */
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
        $this->run?->finish();
    }

    /** Back to "applied", so it can be rolled back again (what was rolled back so far is kept in its state). */
    private function restore(Replacement $operation, string $reason): void
    {
        $operation->refresh();
        $operation->status = Replacement::STATUS_APPLIED;
        $operation->save();
        ElementRelations::log()->warning(sprintf('Rollback of replacement #%d not finished: %s', $operation->id, $reason));
    }

    #[Override]
    protected function defaultDescription(): string
    {
        return t('rollback-job-description', category: 'element-relations');
    }

    /** The next batch loads the operation and the run again (they would go stale in the queue). */
    public function __clone(): void
    {
        $this->operation = null;
        $this->run = null;
    }
}
