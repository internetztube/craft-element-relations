<?php

declare(strict_types=1);

namespace internetztube\elementRelations\jobs;

use Craft;
use craft\base\Batchable;
use craft\elements\User;
use craft\queue\BaseBatchedJob;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\records\ReplacementRecord;
use internetztube\elementRelations\services\ReplaceService;
use internetztube\elementRelations\services\RollbackRun;

/**
 * Pro: rolls a replacement back (Undo, Roll back, Revert to revisions in the control panel) as the user who asked, in
 * batches of owner groups like ReplaceElementJob.
 */
class RollbackJob extends BaseBatchedJob
{
    public int $operationId;

    /** @var ReplaceService::ROLLBACK_* */
    public string $mode = ReplaceService::ROLLBACK_SWAP;

    /** Who asked for the rollback; null in console runs */
    public ?int $userId = null;

    private ?ReplacementRecord $operation = null;
    private ?RollbackRun $run = null;

    public function execute($queue): void
    {
        $operation = ReplacementRecord::findOne($this->operationId);
        if (!$operation || $operation->status !== ReplacementRecord::STATUS_ROLLBACK_QUEUED) {
            return;
        }
        $session = Craft::$app->getUser();
        $previousIdentity = $session->getIdentity();
        try {
            if ($this->userId !== null) {
                // as the user who asked, if they still may; otherwise the operation stays as it was
                $user = User::find()->id($this->userId)->status(null)->one();
                if (!$user instanceof User || !ElementRelations::canRollBack($user) || !ElementRelations::canRollBackOperation($operation, $user)) {
                    $this->restore($operation, sprintf('user #%d is gone or may no longer roll it back', $this->userId));
                    return;
                }
                $session->setIdentity($user);
            }
            $this->operation = $operation;
            $this->run = RollbackRun::start($operation, $this->mode, $this->userId);
            parent::execute($queue);
        } catch (\Throwable $e) {
            $this->run?->persist();
            $this->restore($operation, $e->getMessage());
            throw $e;
        } finally {
            $session->setIdentity($previousIdentity); // the queue may go on with other jobs in this request
        }
    }

    protected function loadData(): Batchable
    {
        // also called before execute() (Craft's job description counts the items), so load the operation here
        $operation = $this->operation ?? ReplacementRecord::findOne($this->operationId);
        return new PlanBatch($operation ? RollbackRun::groups($operation) : []);
    }

    protected function processItem(mixed $item): void
    {
        /** @var array{key: string, changes: list<array{elementId: int, siteId: int, ownerId?: int, fieldId: int|null, preHadTo: bool, before: mixed, after?: mixed}>} $item */
        $this->run?->processGroup($item);
    }

    protected function afterBatch(): void
    {
        $this->run?->persist();
    }

    protected function after(): void
    {
        $this->run?->finish();
    }

    /** Back to "applied", so it can be rolled back again (what was rolled back so far is kept in its state). */
    private function restore(ReplacementRecord $operation, string $reason): void
    {
        $operation->refresh();
        $operation->status = ReplacementRecord::STATUS_APPLIED;
        $operation->save(false);
        Craft::warning(sprintf('Rollback of replacement #%d not finished: %s', $operation->id, $reason), 'element-relations');
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('element-relations', 'rollback-job-description');
    }
}
