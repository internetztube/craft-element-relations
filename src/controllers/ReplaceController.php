<?php

declare(strict_types=1);

namespace internetztube\elementRelations\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\web\Controller;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\elements\Usage;
use internetztube\elementRelations\models\RelationsModel;
use internetztube\elementRelations\records\ReplacementRecord;
use internetztube\elementRelations\services\ReplaceService;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Pro: the replace wizard (a slideout, templates/_field/wizard.twig) the replace actions open, the replace and rollback
 * endpoints, and the status their notice polls while the queue job runs. Replacements and rollbacks always run in the
 * queue.
 */
class ReplaceController extends Controller
{
    /**
     * Index criteria the wizard's "All … in the list" keeps: the field's filters (type, site, status, drafts, search)
     * and the filter HUD's condition.
     */
    public const LIST_CRITERIA = ['elementTypes', 'inSites', 'status', 'search', 'drafts', 'filterConfig'];

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
        $toId = $this->ids($this->request->getBodyParam('toId'))[0] ?? 0; // the wizard's element select posts a list
        if (!$toId) {
            return $this->asFailure(Craft::t('element-relations', 'replace-choose-replacement'));
        }
        $to = $from ? Craft::$app->getElements()->getElementById($toId, $from::class, $siteId) : null;
        $sourceIds = $this->ids($this->request->getBodyParam('sourceIds', []));
        if (!$from || !$to || $to->id === $from->id) {
            return $this->asFailure(Craft::t('element-relations', 'replace-invalid'));
        }
        // "Replace everywhere…" sends no ids; "Replace…" in the field the ticked usages, or all in its (filtered) list
        $scope = $this->request->getBodyParam('scope');
        $everywhere = $scope === 'everywhere';
        if ($scope === 'list') {
            $sourceIds = $this->listIds($from, $this->criteria($this->request->getBodyParam('criteria')));
        }
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
        // no message: the action shows its own "running" notice and follows the job (actionStatus())
        return $this->asSuccess(null, $result);
    }

    /**
     * Continues a stuck replacement or rollback (the history's Resume button).
     */
    public function actionResume(): ?Response
    {
        $operation = $this->ownOperation();
        if (!ReplaceService::isStuck($operation)) {
            return $this->asFailure(Craft::t('element-relations', 'resume-not-needed'));
        }
        ReplaceService::resume($operation);
        return $this->asSuccess(Craft::t('element-relations', 'resume-done'));
    }

    /**
     * Where a queued replacement or rollback stands; the replace actions' notice polls this until `done`. A stuck one (its job is gone)
     * is resumed on the way.
     */
    public function actionStatus(): Response
    {
        $this->requireAcceptsJson();
        $operation = $this->ownOperation();
        if (ReplaceService::isStuck($operation)) {
            ReplaceService::resume($operation);
        }
        $result = $operation->getResult();
        $elements = Craft::$app->getElements();
        $from = $elements->getElementById((int)$operation->fromId, null, (int)$operation->siteId);
        $to = $from ? $elements->getElementById((int)$operation->toId, $from::class, (int)$operation->siteId) : null;
        $message = match ($operation->status) {
            // a rollback that could do nothing leaves the replacement applied; its reasons are the answer to "Undo"
            ReplacementRecord::STATUS_APPLIED => isset($result['rollbackSkipped'])
                ? self::rollbackMessage(0, 0, array_values(array_map('strval', $result['rollbackSkipped'])))
                : (($result['replaced'] ?? 0) === 0 && empty($result['skipped'])
                ? Craft::t('element-relations', 'replace-nothing-done', ['from' => (string)$from])
                : self::resultMessage(['replaced' => (int)($result['replaced'] ?? 0), 'skipped' => array_values(array_map('strval', (array)($result['skipped'] ?? []))), 'withoutRevision' => (int)($result['withoutRevision'] ?? 0)], (string)$from, (string)$to)),
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
     * The wizard as a slideout: pick the replacement, check the usages (the ones ticked in the field's list, all in
     * that list with its filters, or everywhere), review, replace in the background. Craft's index selects within one
     * page; "all in the list" covers the rest.
     */
    public function actionWizard(): Response
    {
        if (!ElementRelations::canReplace()) {
            throw new ForbiddenHttpException('User is not permitted to replace element references.');
        }
        $siteId = $this->intQueryParam('siteId');
        $from = Craft::$app->getElements()->getElementById($this->intQueryParam('fromId'), null, $siteId);
        if (!$from || !Craft::$app->getElements()->canView($from)) {
            throw new ForbiddenHttpException('Unknown element.');
        }
        $everywhere = $this->request->getQueryParam('scope') === 'everywhere';
        $sourceIds = $everywhere ? null : $this->ids($this->request->getQueryParam('sourceIds', []));
        $criteria = $this->criteria($this->request->getQueryParam('criteria'));
        $listRows = $sourceIds ? $this->listRows($from, $criteria) : [];
        $listIds = array_values(array_unique($listRows));
        $usages = $sourceIds ? $this->wizardUsages($from, $sourceIds) : [];
        return $this->asCpScreen()
            ->title(Craft::t('element-relations', 'wizard-title', ['element' => (string)$from]))
            ->action('element-relations/replace/replace')
            ->submitButtonLabel(Craft::t('element-relations', 'wizard-submit'))
            ->contentTemplate('element-relations/_field/wizard', [
                'from' => $from,
                'siteId' => $siteId,
                'everywhere' => $everywhere,
                'usages' => $usages,
                'total' => $everywhere ? (new RelationsModel((int)$from->id, (int)$from->siteId))->getSourceOwnerCount() : count($usages),
                // every site the elements use it in is replaced (a page in English and German, both); for the whole
                // list, the list's elements
                'sites' => $this->wizardSites($from, count($listIds) > count($usages) ? $listIds : $sourceIds),
                // more in the list than ticked (other pages): offer all of them; preselected when the whole page was ticked
                'listTotal' => count($listIds) > count($usages) ? count($listIds) : 0,
                'allTicked' => $this->request->getQueryParam('allTicked') === '1',
                'criteria' => $criteria,
                'revisionHint' => \internetztube\elementRelations\fields\ElementRelationsField::revisionHintFor($from, count($listIds) > count($usages) ? $listIds : $sourceIds),
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
        // the notice follows the job itself (no message); the history's forms get a flash message
        $message = $this->request->getAcceptsJson() ? null : Craft::t('element-relations', 'rollback-queued');
        return $this->asSuccess($message, ['operationId' => (int)$operation->id, 'queued' => true]);
    }

    /** @param list<string> $skipped */
    public static function rollbackMessage(int $restored, int $reverted, array $skipped): string
    {
        $message = match (true) {
            $reverted > 0 => Craft::t('element-relations', 'rollback-reverted', ['count' => $restored, 'reverted' => $reverted]),
            $restored === 0 && $skipped !== [] => Craft::t('element-relations', 'rollback-nothing'),
            default => Craft::t('element-relations', 'rollback-done', ['count' => $restored]),
        };
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
     * The ticked usages, one per relating element (its sites listed together), as the user may see them (UsageQuery
     * restricts control panel requests).
     * @param list<int> $ids
     * @return list<array{id: int, element: ElementInterface, where: string, field: string, sites: list<string>}>
     */
    private function wizardUsages(ElementInterface $from, array $ids): array
    {
        // the ids into the SQL as text: PostgreSQL binds at most 65,535 parameters
        $query = Usage::find()->allSites()->status(null)->drafts(null)->orderBy(['elements.dateUpdated' => SORT_DESC]);
        $query->andWhere(\internetztube\elementRelations\ElementRelations::idIn('elements.id', $ids));
        Craft::configure($query, ['usesElement' => $from->id]); // behavior param, see ElementQueryBehavior
        $rows = [];
        foreach ($query->all() as $element) { // one per element; its Site column names every site it uses $from in
            $rows[] = [
                'id' => (int)$element->id,
                'element' => $element,
                'where' => strip_tags($element->getAttributeHtml(Usage::ATTR_WHERE)),
                'field' => strip_tags($element->getAttributeHtml(Usage::ATTR_FIELD)),
                'sites' => Craft::$app->getIsMultiSite() ? explode(', ', html_entity_decode(strip_tags($element->getAttributeHtml(Usage::ATTR_SITE)))) : [],
            ];
        }
        return $rows;
    }

    /**
     * Names of the sites the usages are in (the ticked ones, or all); the wizard shows them from two on.
     * @param list<int>|null $sourceIds
     * @return list<string>
     */
    private function wizardSites(ElementInterface $from, ?array $sourceIds): array
    {
        $siteIds = (new \craft\db\Query())
            ->select(['sourceSiteId'])
            ->distinct()
            ->from('{{%elementrelations_cache}}')
            ->where(['targetElementId' => $from->id])
            ->andWhere(['not', ['sourceElementId' => null]])
            ->andWhere($sourceIds === null ? [] : \internetztube\elementRelations\ElementRelations::idIn('sourcePrimaryOwnerId', $sourceIds)) // as text: at most 65,535 parameters
            ->column();
        $sites = Craft::$app->getSites();
        return array_values(array_filter(array_map(
            fn(mixed $id): ?string => is_numeric($id) ? $sites->getSiteById((int)$id, true)?->getName() : null,
            $siteIds,
        )));
    }

    /**
     * The relating elements in the filtered list, each once.
     * @param array<string, mixed> $criteria
     * @return list<int>
     */
    private function listIds(ElementInterface $from, array $criteria): array
    {
        return array_values(array_unique($this->listRows($from, $criteria)));
    }

    /**
     * The rows of the field's list with these filters, as the user may see them: one per relating element and site,
     * so an id repeats for each of its sites (a "place").
     * @param array<string, mixed> $criteria
     * @return list<int>
     */
    private function listRows(ElementInterface $from, array $criteria): array
    {
        $filter = $criteria['filterConfig'] ?? null;
        unset($criteria['filterConfig']);
        $query = Usage::find()->allSites()->status(null);
        Craft::configure($query, $criteria + ['usesElement' => $from->id]); // usesElement: behavior param
        if (is_array($filter) && !empty($filter['conditionRules']) && is_array($filter['conditionRules'])) {
            // the list's own condition (UsageCondition), which knows the stock rules of the types in this list; Craft's
            // plain ElementCondition would drop them without a word
            Usage::$listedTypes = Usage::typesUsing([(int)$from->id]);
            $condition = Usage::createCondition();
            $condition->setConditionRules($filter['conditionRules']);
            $condition->modifyQuery($query);
            Usage::$listedTypes = null;
        }
        return array_values(array_map(intval(...), $query->ids()));
    }

    /**
     * The list's filters from a request value (an array, or its JSON), only the params of LIST_CRITERIA.
     * @return array<string, mixed>
     */
    private function criteria(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        return is_array($value) ? array_intersect_key($value, array_flip(self::LIST_CRITERIA)) : [];
    }

    /** @return list<int> the positive ids of a request value (a list, or one id) */
    private function ids(mixed $value): array
    {
        return array_values(array_filter(array_map(fn(mixed $id): int => is_numeric($id) ? (int)$id : 0, (array)$value)));
    }

    private function intQueryParam(string $name): int
    {
        $value = $this->request->getRequiredQueryParam($name);
        return is_numeric($value) ? (int)$value : 0;
    }

    /** The operation of the posted id, if the current user started it (admins: any); 403 otherwise. */
    private function ownOperation(): ReplacementRecord
    {
        $operation = ReplacementRecord::findOne($this->intBodyParam('operationId'));
        $user = Craft::$app->getUser()->getIdentity();
        if (!$operation || !$user || (!$user->admin && (int)$operation->userId !== (int)$user->id)) {
            throw new ForbiddenHttpException('Unknown replacement.');
        }
        return $operation;
    }

    /** A required id from the request body; anything that is not a number becomes 0, which matches no record. */
    private function intBodyParam(string $name): int
    {
        $value = $this->request->getRequiredBodyParam($name);
        return is_numeric($value) ? (int)$value : 0;
    }
}
