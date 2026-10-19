<?php

declare(strict_types=1);

namespace internetztube\elementRelations\controllers;

use Craft;
use craft\web\Controller;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\models\RelationsModel;
use internetztube\elementRelations\records\ReplacementRecord;
use internetztube\elementRelations\services\ReplaceService;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Pro: the field's replace wizard (a slideout, templates/_field/wizard.twig), the replace and rollback endpoints, and
 * the status the field polls while the queue job runs. Replacements and rollbacks always run in the queue.
 */
class ReplaceController extends Controller
{
    /** Usages the wizard lists to tick; beyond that it offers "everywhere" (and the console for finer choices). */
    public const WIZARD_LIST_LIMIT = 100;

    public function beforeAction($action): bool
    {
        if ($action->id !== 'wizard') {
            $this->requirePostRequest();
        }
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
        $toId = $this->firstId($this->request->getBodyParam('toId')); // the wizard's element select posts a list
        if (!$toId) {
            return $this->asFailure(Craft::t('element-relations', 'replace-choose-replacement'));
        }
        $to = $from ? Craft::$app->getElements()->getElementById($toId, $from::class, $siteId) : null;
        $sourceIds = array_values(array_filter(array_map(
            fn(mixed $id): int => is_numeric($id) ? (int)$id : 0,
            (array)$this->request->getBodyParam('sourceIds', []),
        )));
        if (!$from || !$to || $to->id === $from->id) {
            return $this->asFailure(Craft::t('element-relations', 'replace-invalid'));
        }
        // "everywhere" (the wizard's choice for elements with many usages) sends no ids
        $everywhere = $this->request->getBodyParam('scope') === 'everywhere';
        if (!$everywhere && !$sourceIds) {
            return $this->asFailure(Craft::t('element-relations', 'replace-nothing-selected'));
        }

        $result = ReplaceService::replace($from, $to, $everywhere ? null : $sourceIds, queue: true);
        if ($result['denied']) {
            throw new ForbiddenHttpException('User is not permitted to view one of the elements.');
        }
        if (!$result['queued']) {
            return $this->asFailure(Craft::t('element-relations', 'replace-nothing-done', ['from' => (string)$from]));
        }
        // no message: the field shows its own "running" notice and follows the job (actionStatus())
        return $this->asSuccess(null, $result);
    }

    /**
     * Where a queued replacement or rollback stands; the field polls this until `done`.
     */
    public function actionStatus(): Response
    {
        $this->requireAcceptsJson();
        $operation = ReplacementRecord::findOne($this->intBodyParam('operationId'));
        $user = Craft::$app->getUser()->getIdentity();
        if (!$operation || !$user || (!$user->admin && (int)$operation->userId !== (int)$user->id)) {
            throw new ForbiddenHttpException('Unknown replacement.');
        }
        $result = $operation->getResult();
        $elements = Craft::$app->getElements();
        $from = $elements->getElementById((int)$operation->fromId, null, (int)$operation->siteId);
        $to = $from ? $elements->getElementById((int)$operation->toId, $from::class, (int)$operation->siteId) : null;
        $message = match ($operation->status) {
            ReplacementRecord::STATUS_APPLIED => ($result['replaced'] ?? 0) === 0 && empty($result['skipped'])
                ? Craft::t('element-relations', 'replace-nothing-done', ['from' => (string)$from])
                : self::resultMessage(['replaced' => (int)($result['replaced'] ?? 0), 'skipped' => array_values(array_map('strval', (array)($result['skipped'] ?? []))), 'withoutRevision' => (int)($result['withoutRevision'] ?? 0)], (string)$from, (string)$to),
            ReplacementRecord::STATUS_ROLLED_BACK => self::rollbackMessage((int)($result['restored'] ?? 0), (int)($result['reverted'] ?? 0), array_values(array_map('strval', (array)($result['rollbackSkipped'] ?? [])))),
            ReplacementRecord::STATUS_FAILED => implode(' ', array_map('strval', (array)($result['skipped'] ?? []))),
            default => null,
        };
        return $this->asJson([
            'operationId' => (int)$operation->id,
            'status' => $operation->status,
            'done' => in_array($operation->status, [ReplacementRecord::STATUS_APPLIED, ReplacementRecord::STATUS_ROLLED_BACK, ReplacementRecord::STATUS_FAILED], true),
            'message' => $message,
            'canUndo' => $operation->status === ReplacementRecord::STATUS_APPLIED && ($result['replaced'] ?? 0) > 0 && ElementRelations::canRollBackOperation($operation),
            'progress' => self::progress($operation),
        ]);
    }

    /** 0..1 while a batched run is underway (owner groups done of all), null before its first batch and when done. */
    private static function progress(ReplacementRecord $operation): ?float
    {
        $state = $operation->getState();
        $total = is_numeric($state['total'] ?? null) ? (int)$state['total'] : 0;
        $processed = is_numeric($state['processed'] ?? null) ? (int)$state['processed'] : 0;
        return $total > 0 ? min(1, $processed / $total) : null;
    }

    /**
     * The wizard as a slideout: pick the replacement, tick the usages, review, replace in the background.
     */
    public function actionWizard(): Response
    {
        if (!ElementRelations::canReplace()) {
            throw new ForbiddenHttpException('User is not permitted to replace element references.');
        }
        $siteId = $this->firstId($this->request->getRequiredQueryParam('siteId'));
        $from = Craft::$app->getElements()->getElementById($this->firstId($this->request->getRequiredQueryParam('fromId')), null, $siteId);
        if (!$from || !Craft::$app->getElements()->canView($from)) {
            throw new ForbiddenHttpException('Unknown element.');
        }
        $total = (new RelationsModel((int)$from->id, (int)$from->siteId))->getSourceOwnerCount();
        return $this->asCpScreen()
            ->title(Craft::t('element-relations', 'wizard-title', ['element' => (string)$from]))
            ->action('element-relations/replace/replace')
            ->submitButtonLabel(Craft::t('element-relations', 'wizard-submit'))
            ->contentTemplate('element-relations/_field/wizard', [
                'from' => $from,
                'siteId' => $siteId,
                'usages' => $this->wizardUsages($from),
                'total' => $total,
                'limited' => $total > self::WIZARD_LIST_LIMIT,
                'revisionHint' => \internetztube\elementRelations\fields\ElementRelationsField::revisionHintFor($from),
            ]);
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
        ReplaceService::queueRollback($operation, $mode);
        // the field follows the job itself (no message); the history's forms get a flash message
        $message = $this->request->getAcceptsJson() ? null : Craft::t('element-relations', 'rollback-queued');
        return $this->asSuccess($message, ['operationId' => (int)$operation->id, 'queued' => true]);
    }

    /** @param list<string> $skipped */
    public static function rollbackMessage(int $restored, int $reverted, array $skipped): string
    {
        $message = $reverted
            ? Craft::t('element-relations', 'rollback-reverted', ['count' => $restored, 'reverted' => $reverted])
            : Craft::t('element-relations', 'rollback-done', ['count' => $restored]);
        if ($skipped) {
            $message .= ' ' . Craft::t('element-relations', 'replace-skipped', ['count' => count($skipped), 'list' => implode(', ', $skipped)]);
        }
        return $message;
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

    /**
     * The usages the wizard offers to tick, one per relating element (its sites listed together), as the user may see
     * them (UsageQuery restricts control panel requests).
     * @return list<array{id: int, element: \craft\base\ElementInterface, where: string, field: string, sites: list<string>}>
     */
    private function wizardUsages(\craft\base\ElementInterface $from): array
    {
        // the most recently edited first; elements with thousands of usages list the first ones only
        $query = \internetztube\elementRelations\elements\Usage::find()->allSites()->status(null)->drafts(null)
            ->orderBy(['elements.dateUpdated' => SORT_DESC])
            ->limit(self::WIZARD_LIST_LIMIT * 3);
        Craft::configure($query, ['usesElement' => $from->id]); // behavior param, see ElementQueryBehavior
        $rows = [];
        foreach ($query->all() as $element) {
            if (count($rows) >= self::WIZARD_LIST_LIMIT && !isset($rows[(int)$element->id])) {
                continue;
            }
            $id = (int)$element->id;
            $rows[$id] ??= [
                'id' => $id,
                'element' => $element,
                'where' => strip_tags($element->getAttributeHtml(\internetztube\elementRelations\elements\Usage::ATTR_WHERE)),
                'field' => strip_tags($element->getAttributeHtml(\internetztube\elementRelations\elements\Usage::ATTR_FIELD)),
                'sites' => [],
            ];
            $rows[$id]['sites'][] = $element->getSite()->getName();
        }
        return array_values($rows);
    }

    /** The first id of a value that may be a list (element select inputs post `name[]`); 0 when there is none. */
    private function firstId(mixed $value): int
    {
        $first = is_array($value) ? reset($value) : $value;
        return is_numeric($first) ? (int)$first : 0;
    }

    /** A required id from the request body; anything that is not a number becomes 0, which matches no record. */
    private function intBodyParam(string $name): int
    {
        $value = $this->request->getRequiredBodyParam($name);
        return is_numeric($value) ? (int)$value : 0;
    }
}
