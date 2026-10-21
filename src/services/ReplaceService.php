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
     * @param bool $allowQueue run as a queue job when more elements than the `replaceSyncLimit` setting are affected
     * @return ReplaceResult
     */
    public static function replace(
        ElementInterface $from,
        ElementInterface $to,
        ?array $onlySourceIds = null,
        ?ReplacementRecord $operation = null,
        ?callable $progress = null,
        bool $allowQueue = false,
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

        /** @var list<array{sourceElementId: int|string, sourceSiteId: int|string, sourcePrimaryOwnerId: int|string|null, fieldId: int|string|null, type: string}> $rows */
        $rows = (new Query())
            ->select(['sourceElementId', 'sourceSiteId', 'sourcePrimaryOwnerId', 'fieldId', 'type'])
            ->distinct()
            ->from('{{%elementrelations_cache}}')
            ->where(['targetElementId' => $from->id])
            ->andWhere(['not', ['sourceElementId' => null]]) // non-element usages are reported below
            ->andFilterWhere(['sourcePrimaryOwnerId' => $onlySourceIds])
            ->all();
        $types = self::types([...array_column($rows, 'sourceElementId'), ...array_column($rows, 'sourcePrimaryOwnerId')]);

        $bySource = [];
        foreach ($rows as $row) {
            $bySource["{$row['sourceElementId']}:{$row['sourceSiteId']}"][] = $row;
        }

        if (!$operation && $allowQueue && count(array_unique(array_column($rows, 'sourceElementId'))) > ElementRelations::$plugin->getSettings()->replaceSyncLimit) {
            $operation = self::newOperation($from, $to, $onlySourceIds, $userId, ReplacementRecord::STATUS_QUEUED);
            Craft::$app->getQueue()->push(new ReplaceElementJob(['operationId' => $operation->id]));
            Craft::info(sprintf('Replacement #%d of #%d by #%d queued (%d elements)', $operation->id, $from->id, $to->id, count($bySource)), 'element-relations');
            return [...$nothing, 'operationId' => $operation->id, 'queued' => true];
        }

        $notes = Craft::t('element-relations', 'replace-revision-notes', ['from' => (string)$from, 'to' => (string)$to]);
        $replaced = 0;
        $skipped = [];
        /** @var list<Change> $changes */
        $changes = [];
        /** @var array<string, array{ownerId: int, siteId: int, support: string, revisionBeforeId: int|null, revisionAfterId?: int|null, changed?: bool, nested?: bool}> $owners "ownerId:siteId" => see ownerInfo() */
        $owners = [];
        $saved = []; // element ids rewritten (in whichever site)
        $pending = []; // "not rewritten" per element id; decided once every site has been seen
        $done = 0;
        foreach ($bySource as $key => $sourceRows) {
            if ($progress) {
                $progress($done++ / max(1, count($bySource)));
            }
            [$id, $siteId] = array_map('intval', explode(':', $key));
            $ownerId = (int)($sourceRows[0]['sourcePrimaryOwnerId'] ?: $id);
            $element = self::load($types[$id] ?? null, $id, $siteId);
            if (!$element) {
                continue;
            }
            if ($user && !$elementsService->canSave($element, $user)) {
                $skipped[] = self::label($element, $user) . ' (' . Craft::t('element-relations', 'replace-no-permission') . ')';
                continue;
            }
            $ownerKey = "$ownerId:$siteId";
            $owners[$ownerKey] ??= self::ownerInfo($ownerId === $id ? $element : self::load($types[$ownerId] ?? null, $ownerId, $siteId), $ownerId, $siteId, $userId, $from, $to);

            $elementChanges = [];
            foreach ($sourceRows as $row) {
                $field = $row['fieldId'] ? Craft::$app->getFields()->getFieldById((int)$row['fieldId']) : null;
                $field = $field instanceof Field ? $field : null;
                if ($row['fieldId'] && !$field) {
                    $pending[$id][] = self::label($element, $user);
                    continue;
                }
                if ($field && !self::canEditField($element, $field, $user)) {
                    $skipped[] = self::label($element, $user, $field) . ' (' . Craft::t('element-relations', 'replace-no-permission') . ')';
                    continue;
                }
                $preHadTo = in_array($to->id, ExtractorService::targetIds($element, $field), true);
                $before = $field ? self::serializedFieldValue($field, $element) : null;
                $ok = $field ? self::replaceInField($field, $element, $from, $to) : self::replaceInElement($element, $from, $to);
                if ($ok) {
                    $elementChanges[] = ['elementId' => $id, 'siteId' => $siteId, 'ownerId' => $ownerId, 'fieldId' => $field ? (int)$field->id : null, 'preHadTo' => $preHadTo, 'before' => $before];
                } else {
                    $pending[$id][] = self::label($element, $user, $field);
                }
            }
            if (!$elementChanges) {
                continue;
            }
            $element->setRevisionNotes($notes);
            if ($userId) {
                $element->setRevisionCreatorId($userId);
            }
            if (!$elementsService->saveElement($element)) {
                $skipped[] = self::label($element, $user) . ' (' . implode(' ', $element->getFirstErrors()) . ')';
                continue;
            }
            $replaced++;
            $saved[$id] = true;
            $owners[$ownerKey]['changed'] = true;
            if ($ownerId !== $id) {
                $owners[$ownerKey]['nested'] = true;
            }
            $fresh = self::load($types[$id] ?? null, $id, $siteId);
            foreach ($elementChanges as $change) {
                $field = $change['fieldId'] ? Craft::$app->getFields()->getFieldById($change['fieldId']) : null;
                $change['after'] = $field instanceof Field && $fresh ? self::serializedFieldValue($field, $fresh) : null;
                $changes[] = $change;
            }
        }

        // every changed owner gets a revision of the replaced state (Craft makes none for a nested-only change, and
        // skips one when the previous revision is from the same second)
        $owners = array_filter($owners, fn(array $owner): bool => !empty($owner['changed']));
        foreach ($owners as $key => $owner) {
            $owners[$key]['revisionAfterId'] = self::guaranteeRevision($owner, $owner['revisionBeforeId'], $notes, $userId);
            unset($owners[$key]['changed'], $owners[$key]['nested']);
        }

        // Data shared across sites (non-translatable fields, Formie forms) is swapped by whichever site got saved first;
        // the other sites then hold no reference anymore and Craft fires no save event for them. So: an element saved
        // in any site is refreshed in every site it exists in, and only elements saved nowhere count as skipped.
        foreach ($pending as $id => $labels) {
            if (!isset($saved[$id])) {
                $skipped = [...$skipped, ...$labels];
            }
        }
        self::refreshEverySite(array_keys($saved), $types);

        if ($onlySourceIds === null) {
            foreach ((new RelationsModel((int)$from->id, (int)$from->siteId))->getGlobalUsages() as $usage) {
                $skipped[] = $usage->label;
            }
        }
        $skipped = array_values(array_unique($skipped));
        $withoutRevision = count(array_filter($owners, fn(array $owner): bool => $owner['support'] !== self::REVISIONS_YES));

        if ($changes || $operation) {
            $operation ??= self::newOperation($from, $to, $onlySourceIds, $userId, ReplacementRecord::STATUS_APPLIED);
            $operation->status = ReplacementRecord::STATUS_APPLIED;
            // json_encode() fails only on invalid UTF-8; such a column reads back as empty, as it always did
            $operation->changes = json_encode($changes) ?: null;
            $operation->revisions = json_encode(array_values($owners)) ?: null;
            $operation->result = json_encode(['replaced' => $replaced, 'skipped' => $skipped, 'withoutRevision' => $withoutRevision]) ?: null;
            $operation->save(false);
        }
        if ($progress) {
            $progress(1);
        }
        $result = ['replaced' => $replaced, 'skipped' => $skipped, 'operationId' => $operation?->id, 'queued' => false, 'withoutRevision' => $withoutRevision, 'denied' => false];
        Craft::info(sprintf('Replaced #%d by #%d in %d elements (%d without revision)%s', $from->id, $to->id, $replaced, $withoutRevision, $skipped ? '; skipped: ' . implode(', ', $skipped) : ''), 'element-relations');
        if (Event::hasHandlers(self::class, self::EVENT_AFTER_REPLACE)) {
            Event::trigger(self::class, self::EVENT_AFTER_REPLACE, new ReplaceEvent(['from' => $from, 'to' => $to, 'sourceIds' => $onlySourceIds, 'result' => $result, 'operation' => $operation]));
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
        if ($operation->status !== ReplacementRecord::STATUS_APPLIED) {
            throw new \InvalidArgumentException('Only applied operations can be rolled back.');
        }
        $elementsService = Craft::$app->getElements();
        $from = $elementsService->getElementById((int)$operation->fromId, null, (int)$operation->siteId);
        $to = $from ? $elementsService->getElementById((int)$operation->toId, $from::class, (int)$operation->siteId) : null;
        $userId = self::currentUserId();
        $user = self::permissionUser($userId);
        if ($userId && !$user) {
            throw new \InvalidArgumentException('The acting user does not exist.');
        }
        $notes = $from && $to ? Craft::t('element-relations', 'rollback-revision-notes', ['from' => (string)$from, 'to' => (string)$to]) : null;

        $restored = 0;
        $reverted = 0;
        $skipped = [];
        $saved = [];
        $revertedOwners = [];
        $owners = [];
        foreach ($operation->getRevisions() as $owner) {
            $owners["{$owner['ownerId']}:{$owner['siteId']}"] = $owner;
        }

        // 1. revert owners to their revision from before the replacement (Craft's own revision revert)
        if ($mode === self::ROLLBACK_REVISIONS) {
            foreach ($owners as $key => $owner) {
                if (empty($owner['revisionBeforeId'])) {
                    continue;
                }
                $canonical = Entry::find()->id($owner['ownerId'])->siteId($owner['siteId'])->status(null)->one();
                $revision = Entry::find()->id($owner['revisionBeforeId'])->siteId($owner['siteId'])->revisions()->status(null)->one();
                if (!$canonical instanceof Entry || !$revision instanceof Entry) {
                    continue; // falls back to swapping back below
                }
                if ($user && !$elementsService->canSave($canonical, $user)) {
                    $skipped[] = self::label($canonical, $user) . ' (' . Craft::t('element-relations', 'replace-no-permission') . ')';
                    $revertedOwners[$key] = true; // do not touch it at all
                    continue;
                }
                $currentBefore = $canonical->getCurrentRevision()?->id;
                $creatorId = $userId ?? ($operation->userId !== null ? (int)$operation->userId : self::anyAdminId());
                Craft::$app->getRevisions()->revertToRevision($revision, $creatorId);
                self::guaranteeRevision($owner, $currentBefore, $notes ?? '', $userId);
                self::refreshOwnerTree((int)$owner['ownerId']);
                $revertedOwners[$key] = true;
                $reverted++;
            }
        }

        // 2. swap the reference back everywhere else
        $byElement = [];
        foreach ($operation->getChanges() as $change) {
            $ownerKey = ($change['ownerId'] ?? $change['elementId']) . ':' . $change['siteId'];
            if (!isset($revertedOwners[$ownerKey])) {
                $byElement["{$change['elementId']}:{$change['siteId']}"][] = $change;
            }
        }
        $types = self::types(array_map(fn(string $key): int => (int)explode(':', $key)[0], array_keys($byElement)));
        /** @var array<string, array{OwnerRevisions, int|null, bool}> $touchedOwners ownerKey => [owner info, revision id before the rollback, changed] */
        $touchedOwners = [];
        foreach ($byElement as $key => $changes) {
            [$id, $siteId] = array_map('intval', explode(':', $key));
            $element = self::load($types[$id] ?? null, $id, $siteId);
            if (!$element || !$from || !$to) {
                $skipped[] = Craft::t('element-relations', 'rollback-gone', ['id' => $id]);
                continue;
            }
            if ($user && !$elementsService->canSave($element, $user)) {
                $skipped[] = self::label($element, $user) . ' (' . Craft::t('element-relations', 'replace-no-permission') . ')';
                continue;
            }
            $ownerKey = ($changes[0]['ownerId'] ?? $id) . ':' . $siteId;
            if (isset($owners[$ownerKey]) && !isset($touchedOwners[$ownerKey])) {
                $touchedOwners[$ownerKey] = [$owners[$ownerKey], self::currentRevisionId($owners[$ownerKey]), false];
            }
            $changed = false;
            foreach ($changes as $change) {
                $field = $change['fieldId'] ? Craft::$app->getFields()->getFieldById((int)$change['fieldId']) : null;
                $field = $field instanceof Field ? $field : null;
                if ($field && !self::canEditField($element, $field, $user)) {
                    $skipped[] = self::label($element, $user, $field) . ' (' . Craft::t('element-relations', 'replace-no-permission') . ')';
                    continue;
                }
                if (!$change['preHadTo']) {
                    // the replacement only got there through this operation: swap it back, keep later edits
                    $ok = $field ? self::replaceInField($field, $element, $to, $from) : self::replaceInElement($element, $to, $from);
                } elseif ($field && json_encode(self::serializedFieldValue($field, $element)) === json_encode($change['after'] ?? null)) {
                    // it was referenced before too: restore the previous value exactly, the field is unchanged since
                    $element->setFieldValue($field->handle, $change['before']);
                    $ok = true;
                } else {
                    $ok = false;
                }
                if ($ok) {
                    $changed = true;
                } else {
                    $skipped[] = self::label($element, $user, $field) . ' (' . Craft::t('element-relations', 'rollback-changed-since') . ')';
                }
            }
            if (!$changed) {
                continue;
            }
            if ($notes) {
                $element->setRevisionNotes($notes);
            }
            if ($userId) {
                $element->setRevisionCreatorId($userId);
            }
            if ($elementsService->saveElement($element)) {
                $restored++;
                $saved[$id] = true;
                if (isset($touchedOwners[$ownerKey])) {
                    $touchedOwners[$ownerKey][2] = true; // changed
                }
            } else {
                $skipped[] = self::label($element, $user) . ' (' . implode(' ', $element->getFirstErrors()) . ')';
            }
        }
        foreach ($touchedOwners as [$owner, $revisionBefore, $changedOwner]) {
            if ($changedOwner && $notes) {
                self::guaranteeRevision($owner, $revisionBefore, $notes, $userId);
            }
        }
        self::refreshEverySite(array_keys($saved), $types);

        $operation->status = ReplacementRecord::STATUS_ROLLED_BACK;
        $operation->result = json_encode([...$operation->getResult(), 'restored' => $restored, 'reverted' => $reverted, 'rollbackMode' => $mode, 'rollbackSkipped' => $skipped]) ?: null;
        $operation->save(false);
        Craft::info(sprintf('Rolled back replacement #%d (%s): %d swapped back, %d reverted to revisions%s', $operation->id, $mode, $restored, $reverted, $skipped ? '; skipped: ' . implode(', ', $skipped) : ''), 'element-relations');
        $result = ['restored' => $restored + $reverted, 'skipped' => $skipped, 'reverted' => $reverted];
        if ($from && $to && Event::hasHandlers(self::class, self::EVENT_AFTER_ROLLBACK)) {
            Event::trigger(self::class, self::EVENT_AFTER_ROLLBACK, new ReplaceEvent(['from' => $from, 'to' => $to, 'result' => $result, 'operation' => $operation]));
        }
        return $result;
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
     */
    private static function ownerInfo(?ElementInterface $owner, int $ownerId, int $siteId, ?int $userId, ElementInterface $from, ElementInterface $to): array
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

    /** @param array{ownerId: int, siteId: int, support: string} $owner */
    private static function currentRevisionId(array $owner): ?int
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
     */
    private static function guaranteeRevision(array $owner, ?int $revisionBefore, string $notes, ?int $userId): ?int
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

    /** After a revision revert: the owner and every element it owns, in all sites. */
    private static function refreshOwnerTree(int $ownerId): void
    {
        $ids = [$ownerId, ...array_map(intval(...), (new Query())->select(['elementId'])->from(Table::ELEMENTS_OWNERS)->where(['ownerId' => $ownerId])->column())];
        self::refreshEverySite($ids, self::types($ids));
    }

    /**
     * Craft's field layout editability for this user: a field hidden or read-only for them by its user or element
     * condition (e.g. "only editors may change this") is not touched. Console runs without a user are trusted.
     */
    private static function canEditField(ElementInterface $element, Field $field, ?User $user): bool
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

    /** "Field: Element", or a neutral label when the user may not view the element. */
    private static function label(ElementInterface $element, ?User $user, ?Field $field = null): string
    {
        $name = $user && !Craft::$app->getElements()->canView($element, $user)
            ? Craft::t('element-relations', 'restricted-element')
            : (string)$element;
        return $field ? "{$field->name}: $name" : $name;
    }

    /**
     * @param array<int|string|null> $ids
     * @return array<int, string> element id => type
     */
    private static function types(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(intval(...), $ids))));
        /** @var array<int, string> */
        return $ids ? (new Query())->select(['id', 'type'])->from(Table::ELEMENTS)->where(['id' => $ids])->pairs() : [];
    }

    private static function load(?string $type, int $id, int $siteId): ?ElementInterface
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
     */
    private static function refreshEverySite(array $ids, array $types): void
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
    }

    /** The field's value on the element in the shape it is stored (and posted) in. */
    private static function serializedFieldValue(Field $field, ElementInterface $element): mixed
    {
        return $field->serializeValue($element->getFieldValue($field->handle), $element);
    }

    private static function currentUserId(): ?int
    {
        $identity = Craft::$app->getUser()->getIdentity();
        return $identity ? (int)$identity->id : null;
    }

    /** The oldest admin (deterministic when there are several). */
    private static function anyAdminId(): int
    {
        return (int)User::find()->admin()->status(null)->orderBy(['elements.id' => SORT_ASC])->ids()[0];
    }

    /** The user whose permissions apply (console runs without one are unrestricted). */
    private static function permissionUser(?int $userId): ?User
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
