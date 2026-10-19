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
        $session = Craft::$app->getUser();
        $previousIdentity = $session->getIdentity();
        try {
            $this->run($operation, $queue);
        } finally {
            $session->setIdentity($previousIdentity); // the queue may go on with other jobs in this request
        }
    }

    private function run(ReplacementRecord $operation, $queue): void
    {
        if ($operation->userId) {
            // run as the user who started it, if they still exist and may still replace; otherwise do nothing
            $user = User::find()->id($operation->userId)->status(null)->one();
            if (!$user || !\internetztube\elementRelations\ElementRelations::canReplace($user)) {
                $operation->status = ReplacementRecord::STATUS_FAILED;
                $operation->result = json_encode(['replaced' => 0, 'skipped' => [Craft::t('element-relations', 'replace-job-user-gone')]]);
                $operation->save(false);
                Craft::warning(sprintf('Replacement #%d not run: user #%d is gone or may no longer replace', $operation->id, $operation->userId), 'element-relations');
                return;
            }
            Craft::$app->getUser()->setIdentity($user); // revision creator, permission and field-condition checks
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
