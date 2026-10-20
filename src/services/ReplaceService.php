<?php

declare(strict_types=1);

namespace internetztube\elementRelations\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\User;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\events\ReplaceEvent;
use internetztube\elementRelations\jobs\ReplaceElementJob;
use internetztube\elementRelations\jobs\RollbackJob;
use internetztube\elementRelations\models\RelationsModel;
use internetztube\elementRelations\records\ReplacementRecord;
use internetztube\elementRelations\services\extractors\InterfaceElementReplacer;
use internetztube\elementRelations\services\extractors\InterfaceFieldReplacer;
use yii\base\Event;

/**
 * Pro: replaces usages of one element by another of the same type, going through the relating elements' field values
 * and Craft's save path (cache, search index and revisions stay consistent).
 *
 * Each extractor is also the replacer for its field or element type (InterfaceFieldReplacer / InterfaceElementReplacer),
 * so whatever can be detected can be swapped. Usages without a replacer (plugin settings) are reported as "manual".
 *
 * Permissions: the acting user must be able to view both elements and to save every element that gets changed
 * (Craft's Elements::canView()/canSave(), which include site permissions and other plugins' authorization events).
 * Elements the user may not view are never named in messages.
 *
 * Revisions: every changed element belongs to a top-level owner (the element itself, or the entry a Matrix block
 * belongs to). Owners that are entries with versioning get a revision noting the replacement; when only a nested
 * element changed, the owner is saved as well, since Craft makes no owner revision for a nested save. Other owners
 * (assets, categories, global sets, plugin elements, sections without versioning) get none; the result counts them.
 *
 * Every operation is logged (records\ReplacementRecord) and can be rolled back in two ways:
 * - swap back (default): the replaced reference is swapped back, later edits to the elements stay. Where the
 *   replacement was already referenced before (a relation field that held both), the field's previous value is
 *   restored instead, as long as the field was not changed since.
 * - revert to revisions: owners with a revision from before the replacement are reverted to it with Craft's own
 *   revision revert (later edits to those entries are discarded); everything else is swapped back.
 *
 * @phpstan-import-type Change from ReplacementRecord
 * @phpstan-import-type OwnerRevisions from ReplacementRecord
 * @phpstan-type ReplaceResult array{replaced: int, skipped: list<string>, operationId: int|null, queued: bool, withoutRevision: int, denied: bool}
 * @phpstan-type RollbackResult array{restored: int, skipped: list<string>, reverted: int}
 */
class ReplaceService
{
    /** Before a replacement runs; set `$event->isValid = false` to stop it (ReplaceEvent). */
    public const EVENT_BEFORE_REPLACE = 'beforeReplace';
    /** After a replacement ran (or was queued); `$event->result` and `$event->operation` are set. */
    public const EVENT_AFTER_REPLACE = 'afterReplace';
    /** After an operation was rolled back. */
    public const EVENT_AFTER_ROLLBACK = 'afterRollback';

    public const ROLLBACK_SWAP = 'swap';
    public const ROLLBACK_REVISIONS = 'revisions';

    /** Revision support of an owner, see revisionSupport(). */
    public const REVISIONS_YES = 'yes';
    public const REVISIONS_VERSIONING_OFF = 'versioningOff';
    public const REVISIONS_UNSUPPORTED = 'unsupported';

    /**
     * @param int[]|null $onlySourceIds restrict to these relating elements as listed in the field (top-level owners;
     * nested entries, navigation nodes etc. are matched through their owner); null = everywhere
     * @param ReplacementRecord|null $operation a queued operation to run (ReplaceElementJob); null = start a new one
     * @param (callable(float): void)|null $progress called with 0..1 while running (queue job progress)
     * @param bool $queue run as a queue job (ReplaceElementJob) as the current user; the control panel always does,
     * console commands and migrations run right away
     * @return ReplaceResult
     */
    public static function replace(
        ElementInterface $from,
        ElementInterface $to,
        ?array $onlySourceIds = null,
        ?ReplacementRecord $operation = null,
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
        $elementsService = Craft::$app->getElements();
        if ($userId && !$user) {
            return [...$nothing, 'denied' => true]; // the acting user no longer exists: fail closed
        }
        if ($user && (!$elementsService->canView($from, $user) || !$elementsService->canView($to, $user))) {
            return [...$nothing, 'denied' => true];
        }
        if (!$operation && Event::hasHandlers(self::class, self::EVENT_BEFORE_REPLACE)) {
            $event = new ReplaceEvent(['from' => $from, 'to' => $to, 'sourceIds' => $onlySourceIds]);
            Event::trigger(self::class, self::EVENT_BEFORE_REPLACE, $event);
            if (!$event->isValid) {
                return $nothing;
            }
        }

        if ($queue && !$operation) {
            $anyUsage = (new Query())
                ->from('{{%elementrelations_cache}}')
                ->where(['targetElementId' => $from->id])
                ->andWhere(['not', ['sourceElementId' => null]])
                ->andFilterWhere(['sourcePrimaryOwnerId' => $onlySourceIds])
                ->exists();
            if (!$anyUsage) {
                return $nothing; // nothing to queue: tell right away (e.g. the same usages submitted twice)
            }
            $operation = self::newOperation($from, $to, $onlySourceIds, $userId, ReplacementRecord::STATUS_QUEUED);
            ReplaceRun::plan($operation, $from, $to, $onlySourceIds);
            Craft::$app->getQueue()->push(new ReplaceElementJob(['operationId' => (int)$operation->id]));
            Craft::info(sprintf('Replacement #%d of #%d by #%d queued', $operation->id, $from->id, $to->id), 'element-relations');
            return [...$nothing, 'operationId' => (int)$operation->id, 'queued' => true];
        }

        // right away (console, migrations, or a queued operation run by an older job): every owner group in one go
        $createdHere = $operation === null;
        $operation ??= self::newOperation($from, $to, $onlySourceIds, $userId, ReplacementRecord::STATUS_QUEUED);
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
    public static function rollback(ReplacementRecord $operation, string $mode = self::ROLLBACK_SWAP): array
    {
        if (!in_array($operation->status, [ReplacementRecord::STATUS_APPLIED, ReplacementRecord::STATUS_ROLLBACK_QUEUED], true)) {
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

    /** Every 100 owner groups in one process (console, migrations): flush Craft's log buffer and collect garbage. */
    private static function keepMemoryFlat(int $i): void
    {
        if ($i % 100 === 99) {
            Craft::getLogger()->flush(true);
            gc_collect_cycles();
        }
    }

    /**
     * Rolls an operation back in a queue job (RollbackJob) as the current user, like the control panel's Undo and Roll
     * back buttons do; the operation shows as "rolling back" until the job has run.
     * @param self::ROLLBACK_* $mode
     */
    public static function queueRollback(ReplacementRecord $operation, string $mode = self::ROLLBACK_SWAP): void
    {
        if ($operation->status !== ReplacementRecord::STATUS_APPLIED) {
            throw new \InvalidArgumentException('Only applied operations can be rolled back.');
        }
        $operation->status = ReplacementRecord::STATUS_ROLLBACK_QUEUED;
        $operation->save(false);
        Craft::$app->getQueue()->push(new RollbackJob(['operationId' => (int)$operation->id, 'mode' => $mode, 'userId' => self::currentUserId()]));
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
     * @param int[]|null $onlyOwnerIds
     * @return array{total: int, versioningOff: int, unsupported: int}
     */
    public static function revisionCoverage(int $elementId, ?array $onlyOwnerIds = null): array
    {
        /** @var list<array{id: int|string, type: string, sectionId: int|string|null}> $owners */
        $owners = (new Query())
            ->select(['owners.id', 'owners.type', 'entries.sectionId'])
            ->distinct()
            ->from(['cache' => '{{%elementrelations_cache}}'])
            ->innerJoin(['owners' => Table::ELEMENTS], '[[owners.id]] = [[cache.sourcePrimaryOwnerId]]')
            ->leftJoin(['entries' => Table::ENTRIES], '[[entries.id]] = [[owners.id]]')
            ->where(['cache.targetElementId' => $elementId])
            ->andWhere(['not', ['cache.sourceElementId' => null]])
            ->andWhere(['owners.dateDeleted' => null, 'owners.revisionId' => null])
            ->andFilterWhere(['owners.id' => $onlyOwnerIds === null ? null : ($onlyOwnerIds ?: [0])])
            ->all();
        $coverage = ['total' => count($owners), 'versioningOff' => 0, 'unsupported' => 0];
        foreach ($owners as $owner) {
            if (!is_a($owner['type'], Entry::class, true)) {
                $coverage['unsupported']++;
            } elseif (!($owner['sectionId'] && Craft::$app->getEntries()->getSectionById((int)$owner['sectionId'])?->enableVersioning)) {
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

    /** Same for usages stored outside custom fields (user photo, navigation node, …), see InterfaceElementReplacer. */
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
            $before = $upToDate ? $current->id : Craft::$app->getRevisions()->createRevision(
                $owner,
                $userId,
                Craft::t('element-relations', 'replace-revision-before-notes', ['from' => (string)$from, 'to' => (string)$to]),
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
        return $entry instanceof Entry ? $entry->getCurrentRevision()?->id : null;
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
        if ($current && $current !== $revisionBefore) {
            return $current;
        }
        return Craft::$app->getRevisions()->createRevision($entry, $userId, $notes ?: null, force: true);
    }

    /**
     * After a revision revert: the owner and every element it owns, in all sites.
     * @internal used by ReplaceRun / RollbackRun
     */
    public static function refreshOwnerTree(int $ownerId): void
    {
        $ids = [$ownerId, ...array_map(intval(...), (new Query())->select(['elementId'])->from(Table::ELEMENTS_OWNERS)->where(['ownerId' => $ownerId])->column())];
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
        // CustomField::editable() reads the current identity; evaluate it for the acting user
        $session = Craft::$app->getUser();
        $previous = $session->getIdentity();
        $session->setIdentity($user);
        try {
            foreach ($layout->getEditableCustomFields($element) as $editable) {
                if ((int)$editable->id === (int)$field->id) {
                    return true;
                }
            }
            return false;
        } finally {
            $session->setIdentity($previous);
        }
    }

    /**
     * "Field: Element", or a neutral label when the user may not view the element.
     * @internal used by ReplaceRun / RollbackRun
     */
    public static function label(ElementInterface $element, ?User $user, ?Field $field = null): string
    {
        $name = $user && !Craft::$app->getElements()->canView($element, $user)
            ? Craft::t('element-relations', 'restricted-element')
            : (string)$element;
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
        return $ids ? (new Query())->select(['id', 'type'])->from(Table::ELEMENTS)->where(['id' => $ids])->pairs() : [];
    }

    /** @internal for ReplaceRun / RollbackRun */
    public static function load(?string $type, int $id, int $siteId): ?ElementInterface
    {
        if ($type === null || !is_subclass_of($type, ElementInterface::class)) {
            return null;
        }
        $element = $type::find()->id($id)->siteId($siteId)->status(null)->drafts(null)->provisionalDrafts(null)->one();
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
            /** @var ElementInterface[] $siteElements */
            $siteElements = $type::find()->id($id)->site('*')->status(null)->drafts(null)->provisionalDrafts(null)->all();
            foreach ($siteElements as $siteElement) {
                ExtractorService::refreshRelationsForElement($siteElement);
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
        return $field->serializeValue($element->getFieldValue($field->handle), $element);
    }

    /** @internal for ReplaceRun / RollbackRun */
    public static function currentUserId(): ?int
    {
        $identity = Craft::$app->getUser()->getIdentity();
        return $identity ? (int)$identity->id : null;
    }

    /**
     * The oldest admin (deterministic when there are several).
     * @internal used by ReplaceRun / RollbackRun
     */
    public static function anyAdminId(): int
    {
        return (int)User::find()->admin()->status(null)->orderBy(['elements.id' => SORT_ASC])->ids()[0];
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

    /** @param int[]|null $onlySourceIds */
    private static function newOperation(ElementInterface $from, ElementInterface $to, ?array $onlySourceIds, ?int $userId, string $status): ReplacementRecord
    {
        $operation = new ReplacementRecord([
            'fromId' => $from->id,
            'toId' => $to->id,
            'siteId' => $from->siteId,
            'userId' => $userId,
            'sourceIds' => $onlySourceIds !== null ? json_encode(array_values($onlySourceIds)) : null,
            'status' => $status,
        ]);
        $operation->save(false);
        return $operation;
    }
}
