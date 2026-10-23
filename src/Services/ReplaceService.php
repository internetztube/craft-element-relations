<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Services;

use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Field;
use CraftCms\Cms\Support\Facades\Revisions;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Facades\DB;
use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Events\ReplacingUsages;
use Internetztube\ElementRelations\Extractors\InterfaceElementReplacer;
use Internetztube\ElementRelations\Extractors\InterfaceFieldReplacer;
use Internetztube\ElementRelations\Jobs\ReplaceElementJob;
use Internetztube\ElementRelations\Jobs\RollbackJob;
use Internetztube\ElementRelations\Models\CacheRecord;
use Internetztube\ElementRelations\Models\Replacement;
use Internetztube\ElementRelations\Support\ActingUser;

use function CraftCms\Cms\currentUserElement;
use function CraftCms\Cms\t;

/**
 * Replaces usages of one element by another of the same type, going through the relating elements' field values and
 * Craft's save path (cache, search index and revisions stay consistent).
 *
 * Each extractor is also the replacer for its field or element type (InterfaceFieldReplacer / InterfaceElementReplacer),
 * so whatever can be detected can be swapped. Usages without a replacer (plugin settings) are reported as "manual".
 *
 * Permissions: the acting user must be able to view both elements and to save every element that gets changed
 * (Craft's view and save permissions, which include site permissions and other plugins' authorization rules).
 * Elements the user may not view are never named in messages.
 *
 * Revisions: every changed element belongs to a top-level owner (the element itself, or the entry a Matrix block
 * belongs to). Owners that are entries with versioning get a revision noting the replacement; when only a nested
 * element changed, the owner is saved as well, since Craft makes no owner revision for a nested save. Other owners
 * (assets, users, plugin elements, sections without versioning) get none; the result counts them.
 *
 * Every operation is logged (Models\Replacement) and can be rolled back in two ways:
 * - swap back (default): the replaced reference is swapped back, later edits to the elements stay. Where the
 *   replacement was already referenced before (a relation field that held both), the field's previous value is
 *   restored instead, as long as the field was not changed since.
 * - revert to revisions: owners with a revision from before the replacement are reverted to it with Craft's own
 *   revision revert (later edits to those entries are discarded); everything else is swapped back.
 *
 * @phpstan-type ReplaceResult array{replaced: int, skipped: list<string>, operationId: int|null, queued: bool, withoutRevision: int, denied: bool}
 * @phpstan-type RollbackResult array{restored: int, skipped: list<string>, reverted: int}
 */
class ReplaceService
{
    public const ROLLBACK_SWAP = 'swap';
    public const ROLLBACK_REVISIONS = 'revisions';

    /** Revision support of an owner, see revisionSupport(). */
    public const REVISIONS_YES = 'yes';
    public const REVISIONS_VERSIONING_OFF = 'versioningOff';
    public const REVISIONS_UNSUPPORTED = 'unsupported';

    /**
     * @param list<int>|null $onlySourceIds restrict to these relating elements as listed in the field (top-level owners;
     * nested entries are matched through their owner); null = everywhere
     * @param Replacement|null $operation a queued operation to run; null = start a new one
     * @param (callable(float): void)|null $progress called with 0..1 while running
     * @param bool $queue run as a queue job (ReplaceElementJob) as the current user; the control panel always does,
     * console commands and migrations run right away
     * @return ReplaceResult
     */
    public static function replace(
        ElementInterface $from,
        ElementInterface $to,
        ?array $onlySourceIds = null,
        ?Replacement $operation = null,
        ?callable $progress = null,
        bool $queue = false,
    ): array {
        $nothing = ['replaced' => 0, 'skipped' => [], 'operationId' => null, 'queued' => false, 'withoutRevision' => 0, 'denied' => false];
        if ($from::class !== $to::class || $from->id === $to->id) {
            throw new \InvalidArgumentException('Replacement must be a different element of the same type.');
        }
        if ($onlySourceIds === []) {
            return $nothing; // "none selected" must never mean "everywhere"
        }
        $userId = $operation?->userId !== null ? (int)$operation->userId : self::currentUserId();
        $user = self::permissionUser($userId);
        if ($userId && !$user) {
            return [...$nothing, 'denied' => true]; // the acting user no longer exists: fail closed
        }
        if ($user && (!$from->canView($user) || !$to->canView($user))) {
            return [...$nothing, 'denied' => true];
        }
        if (!$operation) {
            event($event = new ReplacingUsages($from, $to, $onlySourceIds));
            if (!$event->isValid) {
                return $nothing;
            }
        }

        if ($queue && !$operation) {
            $anyUsage = DB::table(CacheRecord::TABLE)
                ->where('targetElementId', $from->id)
                ->whereNotNull('sourceElementId')
                ->when($onlySourceIds !== null, fn($query) => $query->whereIntegerInRaw('sourcePrimaryOwnerId', (array)$onlySourceIds)) // as text: at most 65,535 parameters
                ->exists();
            if (!$anyUsage) {
                return $nothing; // nothing to queue: tell right away (e.g. the same usages submitted twice)
            }
            $operation = self::newOperation($from, $to, $onlySourceIds, $userId, Replacement::STATUS_QUEUED);
            ReplaceRun::plan($operation, $from, $to, $onlySourceIds);
            dispatch(new ReplaceElementJob((int)$operation->id));
            ElementRelations::log()->info(sprintf('Replacement #%d of #%d by #%d queued', $operation->id, $from->id, $to->id));
            return [...$nothing, 'operationId' => (int)$operation->id, 'queued' => true];
        }

        // right away (console, migrations): every owner group in one go
        $createdHere = $operation === null;
        $operation ??= self::newOperation($from, $to, $onlySourceIds, $userId, Replacement::STATUS_QUEUED);
        $run = $operation->plan ? ReplaceRun::resume($operation, $from, $to) : ReplaceRun::plan($operation, $from, $to, $onlySourceIds);
        $groups = ReplaceRun::groups($operation);
        foreach ($groups as $i => $group) {
            if ($progress) {
                $progress($i / max(1, count($groups)));
            }
            $run->processGroup($group);
            self::keepMemoryFlat($i);
        }
        if ($progress) {
            $progress(1);
        }
        $result = $run->finish($onlySourceIds);
        if ($createdHere && $result['replaced'] === 0) {
            $operation->delete(); // nothing changed: nothing to show in the history or to roll back
            $result['operationId'] = null;
        }
        return $result;
    }

    /**
     * Undoes a replace operation, see the class docs for the two modes.
     * @param self::ROLLBACK_* $mode
     * @return RollbackResult
     */
    public static function rollback(Replacement $operation, string $mode = self::ROLLBACK_SWAP): array
    {
        if (!in_array($operation->status, [Replacement::STATUS_APPLIED, Replacement::STATUS_ROLLBACK_QUEUED], true)) {
            throw new \InvalidArgumentException('Only applied operations can be rolled back.');
        }
        $userId = self::currentUserId();
        if ($userId && !self::permissionUser($userId)) {
            throw new \InvalidArgumentException('The acting user does not exist.');
        }
        $run = RollbackRun::start($operation, $mode, $userId);
        foreach (RollbackRun::groups($operation) as $i => $group) {
            $run->processGroup($group);
            self::keepMemoryFlat($i);
        }
        return $run->finish();
    }

    /** Every 100 owner groups in one process (console, migrations): collect garbage. */
    private static function keepMemoryFlat(int $i): void
    {
        if ($i % 100 === 99) {
            gc_collect_cycles();
        }
    }

    /**
     * Rolls an operation back in a queue job (RollbackJob) as the current user, like the control panel's Undo and Roll
     * back buttons do; the operation shows as "rolling back" until the job has run.
     * @param self::ROLLBACK_* $mode
     */
    public static function queueRollback(Replacement $operation, string $mode = self::ROLLBACK_SWAP): void
    {
        if ($operation->status !== Replacement::STATUS_APPLIED) {
            throw new \InvalidArgumentException('Only applied operations can be rolled back.');
        }
        $operation->status = Replacement::STATUS_ROLLBACK_QUEUED;
        // mode and user in the state too, so a stuck rollback can be resumed (resume())
        $operation->state = json_encode(['mode' => $mode, 'userId' => self::currentUserId()]) ?: null;
        $operation->save();
        dispatch(new RollbackJob((int)$operation->id, $mode, self::currentUserId()));
    }

    /** A running operation without progress for this long is considered stuck; batches save it every few minutes. */
    public const STUCK_AFTER_SECONDS = 600;

    /**
     * Whether a queued replacement or rollback stopped making progress: its job is gone (a worker died, the job lost its
     * reservation or was deleted) and nothing will finish it.
     */
    public static function isStuck(Replacement $operation): bool
    {
        if (!in_array($operation->status, [Replacement::STATUS_QUEUED, Replacement::STATUS_ROLLBACK_QUEUED], true)) {
            return false;
        }
        $updated = $operation->dateUpdated;
        return $updated !== null && $updated->getTimestamp() < time() - self::STUCK_AFTER_SECONDS;
    }

    /**
     * Continues a stuck replacement or rollback with a new queue job, from where it got (owner groups already done are
     * skipped; those of the batch that broke off are handled again, which changes nothing twice).
     */
    public static function resume(Replacement $operation): void
    {
        $state = $operation->getState();
        $offset = is_numeric($state['processed'] ?? null) ? (int)$state['processed'] : 0;
        if ($operation->status === Replacement::STATUS_QUEUED) {
            dispatch(new ReplaceElementJob((int)$operation->id, $offset));
        } elseif ($operation->status === Replacement::STATUS_ROLLBACK_QUEUED) {
            $mode = ($state['mode'] ?? null) === self::ROLLBACK_REVISIONS ? self::ROLLBACK_REVISIONS : self::ROLLBACK_SWAP;
            $userId = is_numeric($state['userId'] ?? null) ? (int)$state['userId'] : null;
            dispatch(new RollbackJob((int)$operation->id, $mode, $userId, $offset));
        } else {
            return;
        }
        $operation->touch(); // not stuck anymore while the new job waits
        ElementRelations::log()->warning(sprintf('Replacement #%d (%s) resumed at owner group %d', $operation->id, $operation->status, $offset));
    }

    /**
     * Whether an owner gets revisions: entries in sections with versioning do; entries in sections without it and all
     * other element types do not.
     * @return self::REVISIONS_*
     */
    public static function revisionSupport(?ElementInterface $owner): string
    {
        if (!$owner instanceof Entry) {
            return self::REVISIONS_UNSUPPORTED;
        }
        return $owner->hasRevisions() ? self::REVISIONS_YES : self::REVISIONS_VERSIONING_OFF;
    }

    /**
     * How many of the usages listed for an element (owners × sites) would get no revision when replaced, by reason.
     * Cheap: one query for the owners' types, sections looked up from Craft's section cache.
     * @param list<int>|null $onlyOwnerIds
     * @return array{total: int, versioningOff: int, unsupported: int}
     */
    public static function revisionCoverage(int $elementId, ?array $onlyOwnerIds = null): array
    {
        $cache = CacheRecord::TABLE;
        $owners = DB::table($cache)
            ->select(['owners.id', 'owners.type', 'entries.sectionId'])
            ->distinct()
            ->join(Table::ELEMENTS . ' as owners', 'owners.id', '=', "$cache.sourcePrimaryOwnerId")
            ->leftJoin(Table::ENTRIES, 'entries.id', '=', 'owners.id')
            ->where("$cache.targetElementId", $elementId)
            ->whereNotNull("$cache.sourceElementId")
            ->whereNull('owners.dateDeleted')
            ->whereNull('owners.revisionId')
            ->when($onlyOwnerIds !== null, fn($query) => $query->whereIntegerInRaw('owners.id', (array)$onlyOwnerIds))
            ->get();
        $coverage = ['total' => count($owners), 'versioningOff' => 0, 'unsupported' => 0];
        foreach ($owners as $owner) {
            /** @var object{id: int|string, type: string, sectionId: int|string|null} $owner */
            if (!is_a($owner->type, Entry::class, true)) {
                $coverage['unsupported']++;
            } elseif (!($owner->sectionId && Sections::getSectionById((int)$owner->sectionId)?->enableVersioning)) {
                $coverage['versioningOff']++;
            }
        }
        return $coverage;
    }

    /**
     * Swaps `$from` for `$to` in the element's value of this field. The field extractors double as replacers
     * (InterfaceFieldReplacer); the first one that recognises the field type decides. False = not supported / no change.
     */
    public static function replaceInField(Field $field, ElementInterface $element, ElementInterface $from, ElementInterface $to): bool
    {
        foreach (ExtractorService::fieldExtractors() as $extractor) {
            if (!is_subclass_of($extractor, InterfaceFieldReplacer::class)) {
                continue;
            }
            $result = $extractor::replace($field, $element, $from, $to);
            if ($result !== null) {
                return $result;
            }
        }
        return false;
    }

    /** Same for usages stored outside custom fields (user photo, …), see InterfaceElementReplacer. */
    public static function replaceInElement(ElementInterface $element, ElementInterface $from, ElementInterface $to): bool
    {
        foreach (ExtractorService::elementExtractors() as $extractor) {
            if (!is_subclass_of($extractor, InterfaceElementReplacer::class)) {
                continue;
            }
            $result = $extractor::replace($element, $from, $to);
            if ($result !== null) {
                return $result;
            }
        }
        return false;
    }

    /**
     * The owner's revision support and its revision of the state right before the replacement. When the owner was
     * changed after its latest revision (Craft makes none for a nested-only save, or skipped one), a revision of the
     * current state is created first, so "revert to revisions" returns to exactly this state.
     * @return array{ownerId: int, siteId: int, support: string, revisionBeforeId: int|null}
     * @internal used by ReplaceRun / RollbackRun
     */
    public static function ownerInfo(?ElementInterface $owner, int $ownerId, int $siteId, ?int $userId, ElementInterface $from, ElementInterface $to): array
    {
        $support = self::revisionSupport($owner);
        $before = null;
        if ($support === self::REVISIONS_YES && $owner instanceof Entry) {
            $current = $owner->getCurrentRevision();
            $upToDate = $current && $current->dateCreated && $owner->dateUpdated
                && $current->dateCreated->getTimestamp() >= $owner->dateUpdated->getTimestamp();
            $before = $upToDate ? (int)$current->id : Revisions::createRevision(
                $owner,
                $userId,
                t('replace-revision-before-notes', ['from' => (string)$from, 'to' => (string)$to], 'element-relations'),
                force: true,
            );
        }
        return ['ownerId' => $ownerId, 'siteId' => $siteId, 'support' => $support, 'revisionBeforeId' => $before];
    }

    /**
     * @param array{ownerId: int, siteId: int, support: string} $owner
     * @internal used by ReplaceRun / RollbackRun
     */
    public static function currentRevisionId(array $owner): ?int
    {
        if ($owner['support'] !== self::REVISIONS_YES) {
            return null;
        }
        $entry = Entry::find()->id($owner['ownerId'])->siteId($owner['siteId'])->status(null)->one();
        $revisionId = $entry instanceof Entry ? $entry->getCurrentRevision()?->id : null;
        return $revisionId !== null ? (int)$revisionId : null;
    }

    /**
     * Makes sure the owner's current state has a revision: when the latest revision is still the one from before
     * (`$revisionBefore`) — a nested-only change, or Craft skipped it because the previous one is from the same
     * second — one is created with these notes. Returns the current revision id.
     * @param array{ownerId: int, siteId: int, support: string} $owner
     * @internal used by ReplaceRun / RollbackRun
     */
    public static function guaranteeRevision(array $owner, ?int $revisionBefore, string $notes, ?int $userId): ?int
    {
        if ($owner['support'] !== self::REVISIONS_YES) {
            return null;
        }
        $entry = Entry::find()->id($owner['ownerId'])->siteId($owner['siteId'])->status(null)->one();
        if (!$entry instanceof Entry) {
            return null;
        }
        $current = $entry->getCurrentRevision()?->id;
        if ($current && (int)$current !== $revisionBefore) {
            return (int)$current;
        }
        return Revisions::createRevision($entry, $userId, $notes ?: null, force: true);
    }

    /**
     * Usages of an element among these relating elements, as the list counts them: an element in one site once,
     * whatever the number of fields or blocks (no trashed elements, no plugin settings). The replacement and the
     * rollback measure what they did by this, among the owners they changed, so their numbers match the list.
     * @param list<int> $ownerIds
     * @internal used by ReplaceRun / RollbackRun
     */
    public static function elementCount(int $targetId, array $ownerIds): int
    {
        if (!$ownerIds) {
            return 0;
        }
        $cache = CacheRecord::TABLE;
        return DB::table($cache)
            ->join(Table::ELEMENTS . ' as sources', 'sources.id', '=', "$cache.sourceElementId")
            ->where("$cache.targetElementId", $targetId)
            ->whereNull('sources.dateDeleted')
            ->whereIntegerInRaw("$cache.sourcePrimaryOwnerId", $ownerIds) // as text: at most 65,535 parameters
            ->distinct()
            ->count("$cache.sourcePrimaryOwnerId");
    }

    /**
     * After a revision revert or a restore: the owner and every element it owns, in all sites.
     * @internal used by ReplaceRun / RollbackRun
     */
    public static function refreshOwnerTree(int $ownerId): void
    {
        $ids = [$ownerId, ...DB::table(Table::ELEMENTS_OWNERS)->where('ownerId', $ownerId)->pluck('elementId')->map(fn(mixed $id): int => is_numeric($id) ? (int)$id : 0)->all()];
        self::refreshEverySite($ids, self::types($ids));
    }

    /**
     * Craft's field layout editability for this user: a field hidden or read-only for them by its user or element
     * condition (e.g. "only editors may change this") is not touched. Console runs without a user are trusted.
     * @internal used by ReplaceRun / RollbackRun
     */
    public static function canEditField(ElementInterface $element, Field $field, ?User $user): bool
    {
        if (!$user || $user->admin) {
            return true;
        }
        $layout = $element->getFieldLayout();
        if (!$layout) {
            return false;
        }
        // CustomField::editable() reads the current user; evaluate it for the acting user
        return ActingUser::run($user, function () use ($layout, $element, $field): bool {
            foreach ($layout->getEditableCustomFields($element) as $editable) {
                if ((int)$editable->id === (int)$field->id) {
                    return true;
                }
            }
            return false;
        });
    }

    /**
     * "Field: Element", or a neutral label when the user may not view the element.
     * @internal used by ReplaceRun / RollbackRun
     */
    public static function label(ElementInterface $element, ?User $user, ?Field $field = null): string
    {
        $name = $user && !$element->canView($user) ? t('restricted-element', category: 'element-relations') : (string)$element;
        return $field ? "{$field->name}: $name" : $name;
    }

    /**
     * @param array<int|string|null> $ids
     * @return array<int, string> element id => type
     * @internal used by ReplaceRun / RollbackRun
     */
    public static function types(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(intval(...), $ids))));
        /** @var array<int, string> */
        return $ids ? DB::table(Table::ELEMENTS)->whereIntegerInRaw('id', $ids)->pluck('type', 'id')->all() : [];
    }

    /** @internal for ReplaceRun / RollbackRun */
    public static function load(?string $type, int $id, int $siteId): ?ElementInterface
    {
        if ($type === null || !is_subclass_of($type, ElementInterface::class)) {
            return null;
        }
        $element = ElementRelations::find($type)->id($id)->siteId($siteId)->status(null)->drafts(null)->provisionalDrafts(null)->one();
        return $element instanceof ElementInterface ? $element : null;
    }

    /**
     * @param int[] $ids
     * @param array<int, string> $types element id => type
     * @internal used by ReplaceRun / RollbackRun
     */
    public static function refreshEverySite(array $ids, array $types): void
    {
        foreach ($ids as $id) {
            $type = $types[$id] ?? null;
            if ($type === null || !is_subclass_of($type, ElementInterface::class)) {
                continue;
            }
            foreach (ElementRelations::find($type)->id($id)->site('*')->status(null)->drafts(null)->provisionalDrafts(null)->all() as $siteElement) {
                if ($siteElement instanceof ElementInterface) {
                    ExtractorService::refreshRelationsForElement($siteElement);
                }
            }
        }
        // every site is up to date now: the refresh their save queued would only repeat it
        QueueElementsForRefreshService::forget(array_map('intval', $ids));
    }

    /**
     * The field's value on the element in the shape it is stored (and posted) in.
     * @internal used by ReplaceRun / RollbackRun
     */
    public static function serializedFieldValue(Field $field, ElementInterface $element): mixed
    {
        return $field->serializeValue($element->getFieldValue((string)$field->handle), $element);
    }

    /** @internal for ReplaceRun / RollbackRun */
    public static function currentUserId(): ?int
    {
        $user = currentUserElement();
        return $user ? (int)$user->id : null;
    }

    /**
     * The oldest admin (deterministic when there are several).
     * @internal used by ReplaceRun / RollbackRun
     */
    public static function anyAdminId(): int
    {
        return (int)User::find()->admin()->status(null)->orderBy('elements.id')->ids()[0];
    }

    /**
     * The user whose permissions apply (console runs without one are unrestricted).
     * @internal used by ReplaceRun / RollbackRun
     */
    public static function permissionUser(?int $userId): ?User
    {
        $user = $userId ? User::find()->id($userId)->status(null)->one() : null;
        return $user instanceof User ? $user : null;
    }

    /** @param list<int>|null $onlySourceIds */
    private static function newOperation(ElementInterface $from, ElementInterface $to, ?array $onlySourceIds, ?int $userId, string $status): Replacement
    {
        $operation = new Replacement([
            'fromId' => (int)$from->id,
            'toId' => (int)$to->id,
            'siteId' => (int)$from->siteId,
            'userId' => $userId,
            'sourceIds' => $onlySourceIds !== null ? json_encode($onlySourceIds) : null,
            'status' => $status,
        ]);
        $operation->save();
        return $operation;
    }
}
