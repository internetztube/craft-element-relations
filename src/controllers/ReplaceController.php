<?php

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

    public function actionReplace(): Response
    {
        $this->requireAcceptsJson();
        $request = Craft::$app->getRequest();
        $siteId = (int)$request->getRequiredBodyParam('siteId');
        $from = Craft::$app->getElements()->getElementById((int)$request->getRequiredBodyParam('fromId'), null, $siteId);
        $to = $from ? Craft::$app->getElements()->getElementById((int)$request->getRequiredBodyParam('toId'), $from::class, $siteId) : null;
        $sourceIds = array_values(array_filter(array_map('intval', (array)$request->getBodyParam('sourceIds', []))));
        if (!$from || !$to || $to->id === $from->id) {
            return $this->asFailure(Craft::t('element-relations', 'replace-invalid'));
        }
        if (!$sourceIds) {
            return $this->asFailure(Craft::t('element-relations', 'replace-nothing-selected'));
        }

        $result = ReplaceService::replace($from, $to, $sourceIds, allowQueue: true);
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
        $operation = ReplacementRecord::findOne((int)Craft::$app->getRequest()->getRequiredBodyParam('operationId'));
        if (!$operation || $operation->status !== ReplacementRecord::STATUS_APPLIED) {
            return $this->asFailure(Craft::t('element-relations', 'rollback-not-possible'));
        }
        $result = ReplaceService::rollback($operation);
        $message = Craft::t('element-relations', 'rollback-done', ['count' => $result['restored']]);
        if ($result['skipped']) {
            $message .= ' ' . Craft::t('element-relations', 'replace-skipped', ['count' => count($result['skipped']), 'list' => implode(', ', $result['skipped'])]);
        }
        return $this->asSuccess($message, $result);
    }

    /** @param array{replaced: int, skipped: string[]} $result */
    public static function resultMessage(array $result, string $from, string $to): string
    {
        $message = Craft::t('element-relations', 'replace-done', ['count' => $result['replaced'], 'from' => $from, 'to' => $to]);
        if ($result['skipped']) {
            $message .= ' ' . Craft::t('element-relations', 'replace-skipped', ['count' => count($result['skipped']), 'list' => implode(', ', $result['skipped'])]);
        }
        return $message;
    }
}
