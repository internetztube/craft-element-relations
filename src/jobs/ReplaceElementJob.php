<?php

namespace internetztube\elementRelations\jobs;

use Craft;
use craft\elements\User;
use craft\queue\BaseJob;
use internetztube\elementRelations\records\ReplacementRecord;
use internetztube\elementRelations\services\ReplaceService;

/** Pro: runs a large replace operation (more elements than the `replaceSyncLimit` setting) as the user who started it. */
class ReplaceElementJob extends BaseJob
{
    public int $operationId;

    public function execute($queue): void
    {
        $operation = ReplacementRecord::findOne($this->operationId);
        if (!$operation || $operation->status !== ReplacementRecord::STATUS_QUEUED) {
            return;
        }
        if ($operation->userId && ($user = User::find()->id($operation->userId)->status(null)->one())) {
            Craft::$app->getUser()->setIdentity($user); // revision creator and per-element permission checks
        }
        $elements = Craft::$app->getElements();
        $from = $elements->getElementById($operation->fromId, null, $operation->siteId);
        $to = $from ? $elements->getElementById($operation->toId, $from::class, $operation->siteId) : null;
        if (!$from || !$to) {
            $operation->status = ReplacementRecord::STATUS_APPLIED;
            $operation->result = json_encode(['replaced' => 0, 'skipped' => [Craft::t('element-relations', 'replace-invalid')]]);
            $operation->save(false);
            return;
        }
        $sourceIds = $operation->sourceIds ? json_decode($operation->sourceIds, true) : null;
        ReplaceService::replace($from, $to, $sourceIds, $operation, fn(float $progress) => $this->setProgress($queue, $progress));
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('element-relations', 'replace-job-description');
    }
}
