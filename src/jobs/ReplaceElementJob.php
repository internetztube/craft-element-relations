<?php

declare(strict_types=1);

namespace internetztube\elementRelations\jobs;

use Craft;
use craft\base\Batchable;
use craft\base\ElementInterface;
use craft\elements\User;
use craft\queue\BaseBatchedJob;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\records\ReplacementRecord;
use internetztube\elementRelations\services\ReplaceRun;

/**
 * Pro: runs a replacement started in the control panel, as the user who started it, in batches of owner groups (an
 * element may have tens of thousands of usages; Craft spawns the next batch before the queue's time limit).
 */
class ReplaceElementJob extends BaseBatchedJob
{
    public int $operationId;

    private ?ReplacementRecord $operation = null;
    private ?ReplaceRun $run = null;

    public function execute($queue): void
    {
        $operation = ReplacementRecord::findOne($this->operationId);
        if (!$operation || $operation->status !== ReplacementRecord::STATUS_QUEUED) {
            return;
        }
        $session = Craft::$app->getUser();
        $previousIdentity = $session->getIdentity();
        try {
            if (!$this->prepare($operation)) {
                return;
            }
            parent::execute($queue);
        } catch (\Throwable $e) {
            $this->stop($operation, $e);
            throw $e;
        } finally {
            $session->setIdentity($previousIdentity); // the queue may go on with other jobs in this request
        }
    }

    /** Sets up the run as the user who started it; false when it must not run (and is marked so). */
    private function prepare(ReplacementRecord $operation): bool
    {
        if ($operation->userId) {
            // run as the user who started it, if they still exist and may still replace; otherwise do nothing
            $user = User::find()->id($operation->userId)->status(null)->one();
            if (!$user instanceof User || !ElementRelations::canReplace($user)) {
                $operation->status = ReplacementRecord::STATUS_FAILED;
                $operation->result = json_encode(['replaced' => 0, 'skipped' => [Craft::t('element-relations', 'replace-job-user-gone')]]) ?: null;
                $operation->save(false);
                Craft::warning(sprintf('Replacement #%d not run: user #%d is gone or may no longer replace', $operation->id, $operation->userId), 'element-relations');
                return false;
            }
            Craft::$app->getUser()->setIdentity($user); // revision creator, permission and field-condition checks
        }
        $elements = Craft::$app->getElements();
        $from = $elements->getElementById((int)$operation->fromId, null, (int)$operation->siteId);
        $to = $from ? $elements->getElementById((int)$operation->toId, $from::class, (int)$operation->siteId) : null;
        if (!$from instanceof ElementInterface || !$to instanceof ElementInterface) {
            $operation->status = ReplacementRecord::STATUS_APPLIED;
            $operation->result = json_encode(['replaced' => 0, 'skipped' => [Craft::t('element-relations', 'replace-invalid')]]) ?: null;
            $operation->save(false);
            return false;
        }
        $this->operation = $operation;
        // operations queued before plans existed get theirs now
        $this->run = $operation->plan ? ReplaceRun::resume($operation, $from, $to) : ReplaceRun::plan($operation, $from, $to, $this->sourceIds($operation));
        return true;
    }

    protected function loadData(): Batchable
    {
        // also called before execute() (Craft's job description counts the items), so load the operation here
        $operation = $this->operation ?? ReplacementRecord::findOne($this->operationId);
        return new PlanBatch($operation ? ReplaceRun::groups($operation) : []);
    }

    protected function processItem(mixed $item): void
    {
        /** @var array{ownerId: int, siteId: int, rows: list<array{sourceElementId: int, sourceSiteId: int, sourcePrimaryOwnerId: int|null, fieldId: int|null, type: string}>} $item */
        $this->run?->processGroup($item);
    }

    protected function afterBatch(): void
    {
        $this->run?->persist();
        // a queue worker runs batch after batch in one process: keep its memory flat
        Craft::getLogger()->flush(true);
        gc_collect_cycles();
    }

    protected function after(): void
    {
        if ($this->run && $this->operation) {
            $this->run->finish($this->sourceIds($this->operation));
        }
    }

    /**
     * A batch failed: keep what was done and make it undoable instead of leaving it "queued" forever.
     */
    private function stop(ReplacementRecord $operation, \Throwable $e): void
    {
        $this->run?->persist();
        $operation->refresh();
        $result = $operation->getResult();
        $result['skipped'] = [...($result['skipped'] ?? []), Craft::t('element-relations', 'replace-stopped', ['error' => $e->getMessage()])];
        $operation->status = ReplacementRecord::STATUS_APPLIED;
        $operation->result = json_encode($result) ?: null;
        $operation->save(false);
        Craft::error(sprintf('Replacement #%d stopped: %s', $operation->id, $e->getMessage()), 'element-relations');
    }

    /** @return list<int>|null */
    private function sourceIds(ReplacementRecord $operation): ?array
    {
        if (!$operation->sourceIds) {
            return null;
        }
        $ids = json_decode($operation->sourceIds, true);
        return is_array($ids) ? array_values(array_map(fn(mixed $id): int => is_numeric($id) ? (int)$id : 0, $ids)) : null;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('element-relations', 'replace-job-description');
    }
}
