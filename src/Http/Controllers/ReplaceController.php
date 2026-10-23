<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Http\Controllers;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Elements\Usage;
use Internetztube\ElementRelations\Fields\ElementRelationsField;
use Internetztube\ElementRelations\Jobs\GenerateResaveAllElementRelationsJobsJob;
use Internetztube\ElementRelations\Models\CacheRecord;
use Internetztube\ElementRelations\Models\RelationsModel;
use Internetztube\ElementRelations\Models\Replacement;
use Internetztube\ElementRelations\Services\ReplaceService;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\currentUserElement;
use function CraftCms\Cms\t;

/**
 * The replace wizard (a slideout, resources/views/_field/wizard.twig) the replace actions open, the replace and
 * rollback endpoints, and the status their notice polls while the queue job runs. Replacements and rollbacks always
 * run in the queue. Control panel only (routes/actions.php also answers on the site's action path).
 */
class ReplaceController
{
    use RespondsWithFlash;

    /**
     * Index criteria the wizard's "All … in the list" keeps: the field's filters (type, status, drafts, search) and the
     * filter HUD's condition.
     */
    public const LIST_CRITERIA = ['elementTypes', 'inSites', 'status', 'search', 'drafts', 'filterConfig'];

    public function __construct(private readonly Request $request)
    {
        abort_unless($request->isCpRequest(), 404);
        abort_unless(ElementRelations::isPro(), 403, 'Pro edition required.');
    }

    public function replace(): Response
    {
        abort_unless(ElementRelations::canReplace(), 403, 'User is not permitted to replace element references.');
        $siteId = $this->request->integer('siteId');
        $from = Elements::getElementById($this->request->integer('fromId'), null, $siteId);
        $toId = $this->ids($this->request->input('toId'))[0] ?? 0; // the wizard's element select posts a list
        if (!$toId) {
            return $this->asFailure(t('replace-choose-replacement', category: 'element-relations'));
        }
        $to = $from ? Elements::getElementById($toId, $from::class, $siteId) : null;
        $sourceIds = $this->ids($this->request->input('sourceIds', []));
        if (!$from || !$to || $to->id === $from->id) {
            return $this->asFailure(t('replace-invalid', category: 'element-relations'));
        }
        // "Replace everywhere…" sends no ids; "Replace…" in the field the ticked usages, or all in its (filtered) list
        $scope = $this->request->input('scope');
        $everywhere = $scope === 'everywhere';
        if ($scope === 'list') {
            $sourceIds = array_values(array_unique($this->listRows($from, $this->criteria($this->request->input('criteria')))));
        }
        if (!$everywhere && !$sourceIds) {
            return $this->asFailure(t('replace-nothing-selected', category: 'element-relations'));
        }

        $result = ReplaceService::replace($from, $to, $everywhere ? null : $sourceIds, queue: true);
        abort_if($result['denied'], 403, 'User is not permitted to view one of the elements.');
        if (!$result['queued']) {
            return $this->asFailure(t('replace-nothing-done', ['from' => (string)$from], 'element-relations'));
        }
        // no message: the action shows its own "running" notice and follows the job (status())
        return $this->asSuccess(null, $result);
    }

    /** Continues a stuck replacement or rollback (the history's Resume button). */
    public function resume(): Response
    {
        $operation = $this->ownOperation();
        if (!ReplaceService::isStuck($operation)) {
            return $this->asFailure(t('resume-not-needed', category: 'element-relations'));
        }
        ReplaceService::resume($operation);
        return $this->asSuccess(t('resume-done', category: 'element-relations'));
    }

    /**
     * Where a queued replacement or rollback stands; the replace actions' notice polls this until `done`. A stuck one
     * (its job is gone) is resumed on the way.
     */
    public function status(): JsonResponse
    {
        $operation = $this->ownOperation();
        if (ReplaceService::isStuck($operation)) {
            ReplaceService::resume($operation);
        }
        $result = $operation->getResult();
        $from = Elements::getElementById($operation->fromId, null, $operation->siteId);
        $to = $from ? Elements::getElementById($operation->toId, $from::class, $operation->siteId) : null;
        $message = match ($operation->status) {
            // a rollback that could do nothing leaves the replacement applied; its reasons are the answer to "Undo"
            Replacement::STATUS_APPLIED => isset($result['rollbackSkipped'])
                ? self::rollbackMessage(0, 0, array_values(array_map('strval', $result['rollbackSkipped'])))
                : (($result['replaced'] ?? 0) === 0 && empty($result['skipped'])
                    ? t('replace-nothing-done', ['from' => (string)$from], 'element-relations')
                    : self::resultMessage(['replaced' => (int)($result['replaced'] ?? 0), 'skipped' => array_values(array_map('strval', (array)($result['skipped'] ?? []))), 'withoutRevision' => (int)($result['withoutRevision'] ?? 0)], (string)$from, (string)$to)),
            Replacement::STATUS_ROLLED_BACK => self::rollbackMessage((int)($result['restored'] ?? 0), (int)($result['reverted'] ?? 0), array_values(array_map('strval', (array)($result['rollbackSkipped'] ?? [])))),
            Replacement::STATUS_FAILED => implode(' ', array_map('strval', (array)($result['skipped'] ?? []))),
            default => null,
        };
        return new JsonResponse([
            'operationId' => (int)$operation->id,
            'status' => $operation->status,
            'done' => in_array($operation->status, [Replacement::STATUS_APPLIED, Replacement::STATUS_ROLLED_BACK, Replacement::STATUS_FAILED], true),
            'message' => $message,
            'canUndo' => $operation->status === Replacement::STATUS_APPLIED && ($result['replaced'] ?? 0) > 0 && ElementRelations::canRollBackOperation($operation),
            'progress' => self::progress($operation),
        ]);
    }

    /** 0..1 while a batched run is underway (owner groups done of all), null before its first batch and when done. */
    private static function progress(Replacement $operation): ?float
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
    public function wizard(): CpScreenResponse
    {
        abort_unless(ElementRelations::canReplace(), 403, 'User is not permitted to replace element references.');
        $siteId = $this->request->integer('siteId');
        $from = Elements::getElementById($this->request->integer('fromId'), null, $siteId);
        $user = currentUserElement();
        abort_if(!$from || !$user || !$from->canView($user), 403, 'Unknown element.');
        /** @var ElementInterface $from */
        $everywhere = $this->request->query('scope') === 'everywhere';
        $sourceIds = $everywhere ? null : $this->ids($this->request->query('sourceIds', []));
        $criteria = $this->criteria($this->request->query('criteria'));
        $listRows = $sourceIds ? $this->listRows($from, $criteria) : [];
        $listIds = array_values(array_unique($listRows));
        $usages = $sourceIds ? $this->wizardUsages($from, $sourceIds) : [];
        return (new CpScreenResponse())
            ->title(t('wizard-title', ['element' => (string)$from], 'element-relations'))
            ->action('element-relations/replace/replace')
            ->submitButtonLabel(t('wizard-submit', category: 'element-relations'))
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
                'allTicked' => $this->request->query('allTicked') === '1',
                'criteria' => $criteria,
                'revisionHint' => ElementRelationsField::revisionHintFor($from, count($listIds) > count($usages) ? $listIds : $sourceIds),
            ]);
    }

    public function rollback(): Response
    {
        abort_unless(ElementRelations::canRollBack(), 403, 'User is not permitted to roll back replacements.');
        $operation = Replacement::find($this->request->integer('operationId'));
        if (!$operation instanceof Replacement || $operation->status !== Replacement::STATUS_APPLIED) {
            return $this->asFailure(t('rollback-not-possible', category: 'element-relations'));
        }
        abort_unless(ElementRelations::canRollBackOperation($operation), 403, 'Only admins can roll back replacements of other users.');
        $mode = $this->request->input('mode') === ReplaceService::ROLLBACK_REVISIONS ? ReplaceService::ROLLBACK_REVISIONS : ReplaceService::ROLLBACK_SWAP;
        ReplaceService::queueRollback($operation, $mode);
        // the notice follows the job itself (no message); the history's forms get a flash message
        $message = $this->request->expectsJson() ? null : t('rollback-queued', category: 'element-relations');
        return $this->asSuccess($message, ['operationId' => (int)$operation->id, 'queued' => true]);
    }

    /** Utilities → Element Relations: rebuild the whole relations cache in the queue. */
    public function rebuild(): Response
    {
        abort_unless(currentUserElement()?->can('utility:element-relations') === true, 403);
        dispatch(new GenerateResaveAllElementRelationsJobsJob());
        return $this->asSuccess(t('utility-form-rebuild-cache-success-notice', category: 'element-relations'));
    }

    /** @param list<string> $skipped */
    public static function rollbackMessage(int $restored, int $reverted, array $skipped): string
    {
        $message = match (true) {
            $reverted > 0 => t('rollback-reverted', ['count' => $restored, 'reverted' => $reverted], 'element-relations'),
            $restored === 0 && $skipped !== [] => t('rollback-nothing', category: 'element-relations'),
            default => t('rollback-done', ['count' => $restored], 'element-relations'),
        };
        if ($skipped) {
            $message .= ' ' . t('replace-skipped', ['count' => count($skipped), 'list' => implode(', ', $skipped)], 'element-relations');
        }
        return $message;
    }

    /** @param array{replaced: int, skipped: list<string>, withoutRevision?: int} $result */
    public static function resultMessage(array $result, string $from, string $to): string
    {
        $message = t('replace-done', ['count' => $result['replaced'], 'from' => $from, 'to' => $to], 'element-relations');
        if (!empty($result['withoutRevision'])) {
            $message .= ' ' . t('replace-without-revision', ['count' => $result['withoutRevision']], 'element-relations');
        }
        if ($result['skipped']) {
            $message .= ' ' . t('replace-skipped', ['count' => count($result['skipped']), 'list' => implode(', ', $result['skipped'])], 'element-relations');
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
        $query = Usage::find()->allSites()->status(null)->drafts(null)->usesElement($from->id)->orderByDesc('elements.dateUpdated');
        $query->getQuery()->whereIntegerInRaw('elements.id', $ids); // as text: PostgreSQL binds at most 65,535 parameters
        $rows = [];
        foreach ($query->all() as $element) { // one per element; its Site column names every site it uses $from in
            if (!$element instanceof ElementInterface) {
                continue;
            }
            $rows[] = [
                'id' => (int)$element->id,
                'element' => $element,
                'where' => strip_tags((string)$element->getAttributeHtml(Usage::ATTR_WHERE)),
                'field' => strip_tags((string)$element->getAttributeHtml(Usage::ATTR_FIELD)),
                'sites' => Sites::isMultiSite() ? explode(', ', html_entity_decode(strip_tags((string)$element->getAttributeHtml(Usage::ATTR_SITE)))) : [],
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
        $siteIds = DB::table(CacheRecord::TABLE)
            ->distinct()
            ->where('targetElementId', $from->id)
            ->whereNotNull('sourceElementId')
            ->when($sourceIds !== null, fn($query) => $query->whereIntegerInRaw('sourcePrimaryOwnerId', (array)$sourceIds)) // as text: at most 65,535 parameters
            ->pluck('sourceSiteId')
            ->all();
        return array_values(array_filter(array_map(
            fn(mixed $id): ?string => is_numeric($id) ? Sites::getSiteById((int)$id, true)?->getName() : null,
            $siteIds,
        )));
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
        \CraftCms\Cms\Support\Typecast::configure($query, $criteria + ['usesElement' => $from->id]);
        if (is_array($filter) && !empty($filter['conditionRules']) && is_array($filter['conditionRules'])) {
            // the list's own condition (UsageCondition), which knows the stock rules of the types in this list
            Usage::$listedTypes = Usage::typesUsing([(int)$from->id]);
            $condition = Usage::createCondition();
            $condition->forQuery = true;
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

    /** The operation of the posted id, if the current user started it (admins: any); 403 otherwise. */
    private function ownOperation(): Replacement
    {
        $operation = Replacement::find($this->request->integer('operationId'));
        $user = currentUserElement();
        abort_if(!$operation instanceof Replacement || !$user || (!$user->admin && (int)$operation->userId !== (int)$user->id), 403, 'Unknown replacement.');
        /** @var Replacement $operation */
        return $operation;
    }
}
