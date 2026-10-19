<?php

declare(strict_types=1);

namespace internetztube\elementRelations\controllers;

use Craft;
use craft\web\Controller;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\records\ReplacementRecord;
use internetztube\elementRelations\services\ReplaceService;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/** Pro: endpoints of the field's replace wizard (templates/_field/replace.twig) and of the rollback buttons. */
class ReplaceController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requirePostRequest();
        $this->requireCpRequest();
        if (!ElementRelations::isPro()) {
            throw new ForbiddenHttpException('Pro edition required.');
        }
        return parent::beforeAction($action);
    }

    public function actionReplace(): ?Response
    {
        $this->requireAcceptsJson();
        if (!ElementRelations::canReplace()) {
            throw new ForbiddenHttpException('User is not permitted to replace element references.');
        }
        $siteId = $this->intBodyParam('siteId');
        $from = Craft::$app->getElements()->getElementById($this->intBodyParam('fromId'), null, $siteId);
        $to = $from ? Craft::$app->getElements()->getElementById($this->intBodyParam('toId'), $from::class, $siteId) : null;
        $sourceIds = array_values(array_filter(array_map(
            fn(mixed $id): int => is_numeric($id) ? (int)$id : 0,
            (array)$this->request->getBodyParam('sourceIds', []),
        )));
        if (!$from || !$to || $to->id === $from->id) {
            return $this->asFailure(Craft::t('element-relations', 'replace-invalid'));
        }
        if (!$sourceIds) {
            return $this->asFailure(Craft::t('element-relations', 'replace-nothing-selected'));
        }

        $result = ReplaceService::replace($from, $to, $sourceIds, allowQueue: true);
        if ($result['denied']) {
            throw new ForbiddenHttpException('User is not permitted to view one of the elements.');
        }
        if ($result['queued']) {
            return $this->asSuccess(Craft::t('element-relations', 'replace-queued', ['from' => (string)$from, 'to' => (string)$to]), $result);
        }
        if ($result['replaced'] === 0 && !$result['skipped']) {
            return $this->asFailure(Craft::t('element-relations', 'replace-nothing-done', ['from' => (string)$from]));
        }
        return $this->asSuccess(self::resultMessage($result, (string)$from, (string)$to), $result);
    }

    public function actionRollback(): ?Response
    {
        if (!ElementRelations::canRollBack()) {
            throw new ForbiddenHttpException('User is not permitted to roll back replacements.');
        }
        $operation = ReplacementRecord::findOne($this->intBodyParam('operationId'));
        if (!$operation || $operation->status !== ReplacementRecord::STATUS_APPLIED) {
            return $this->asFailure(Craft::t('element-relations', 'rollback-not-possible'));
        }
        if (!ElementRelations::canRollBackOperation($operation)) {
            throw new ForbiddenHttpException('Only admins can roll back replacements of other users.');
        }
        $mode = $this->request->getBodyParam('mode') === ReplaceService::ROLLBACK_REVISIONS ? ReplaceService::ROLLBACK_REVISIONS : ReplaceService::ROLLBACK_SWAP;
        $result = ReplaceService::rollback($operation, $mode);
        $message = $result['reverted']
            ? Craft::t('element-relations', 'rollback-reverted', ['count' => $result['restored'], 'reverted' => $result['reverted']])
            : Craft::t('element-relations', 'rollback-done', ['count' => $result['restored']]);
        if ($result['skipped']) {
            $message .= ' ' . Craft::t('element-relations', 'replace-skipped', ['count' => count($result['skipped']), 'list' => implode(', ', $result['skipped'])]);
        }
        return $this->asSuccess($message, $result);
    }

    /** @param array{replaced: int, skipped: list<string>, withoutRevision?: int} $result */
    public static function resultMessage(array $result, string $from, string $to): string
    {
        $message = Craft::t('element-relations', 'replace-done', ['count' => $result['replaced'], 'from' => $from, 'to' => $to]);
        if (!empty($result['withoutRevision'])) {
            $message .= ' ' . Craft::t('element-relations', 'replace-without-revision', ['count' => $result['withoutRevision']]);
        }
        if ($result['skipped']) {
            $message .= ' ' . Craft::t('element-relations', 'replace-skipped', ['count' => count($result['skipped']), 'list' => implode(', ', $result['skipped'])]);
        }
        return $message;
    }

    /** A required id from the request body; anything that is not a number becomes 0, which matches no record. */
    private function intBodyParam(string $name): int
    {
        $value = $this->request->getRequiredBodyParam($name);
        return is_numeric($value) ? (int)$value : 0;
    }
}
