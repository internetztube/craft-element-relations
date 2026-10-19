<?php

namespace internetztube\elementRelations\controllers;

use Craft;
use craft\web\Controller;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\services\ReplaceService;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/** Pro: endpoint of the field's replace wizard (see templates/_field/replace.twig). */
class ReplaceController extends Controller
{
    public function actionReplace(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requireAcceptsJson();
        if (!ElementRelations::isPro()) {
            throw new ForbiddenHttpException('Pro edition required.');
        }

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

        $result = ReplaceService::replace($from, $to, $sourceIds);
        if ($result['replaced'] === 0 && !$result['skipped']) {
            return $this->asFailure(Craft::t('element-relations', 'replace-nothing-done', ['from' => (string)$from]));
        }
        $message = Craft::t('element-relations', 'replace-done', ['count' => $result['replaced'], 'from' => (string)$from, 'to' => (string)$to]);
        if ($result['skipped']) {
            $message .= ' ' . Craft::t('element-relations', 'replace-skipped', ['count' => count($result['skipped']), 'list' => implode(', ', $result['skipped'])]);
        }
        return $this->asSuccess($message, $result);
    }
}
