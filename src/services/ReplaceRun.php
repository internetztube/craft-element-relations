<?php

declare(strict_types=1);

namespace internetztube\elementRelations\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use internetztube\elementRelations\events\ReplaceEvent;
use internetztube\elementRelations\models\RelationsModel;
use internetztube\elementRelations\records\ReplacementRecord;
use yii\base\Event;

/**
 * One replacement while it runs: the plan (the usages, grouped by their top-level owner and site) and what happened so
 * far. An element can have tens of thousands of usages, so the queue job (ReplaceElementJob) works through the plan in
 * batches and saves this state into the operation record between them; the console and migrations run every group in
 * one go. Each owner group is handled completely in one go, so its revision is right.
 *
 * @phpstan-import-type Change from ReplacementRecord
 * @phpstan-import-type OwnerRevisions from ReplacementRecord
 * @phpstan-type SourceRow array{sourceElementId: int, sourceSiteId: int, sourcePrimaryOwnerId: int|null, fieldId: int|null, type: string}
 * @phpstan-type OwnerGroup array{ownerId: int, siteId: int, rows: list<SourceRow>}
 */
final class ReplaceRun
{
    /** @var list<OwnerRevisions> owners that changed, with their revisions before and after */
    private array $owners = [];
    private int $replaced = 0;
    /** @var array<int, true> top-level owners this run changed (or found changed by it) in any site */
    private array $doneOwners = [];
    /** @var list<string> */
    private array $skipped = [];
    /** @var array<int, true> element ids saved in any site */
    private array $saved = [];
    /** @var array<int, list<string>> per element id, usages that could not be rewritten (decided at the end: another site may still do it) */
    private array $pending = [];
    /** owner groups done so far, for the progress the field shows */
    private int $processed = 0;
    /** all owner groups (counted once) */
    private ?int $total = null;

    private ?User $user;
    private string $notes;

    private function __construct(
        private readonly ReplacementRecord $operation,
        private readonly ElementInterface $from,
        private readonly ElementInterface $to,
        private readonly ?int $userId,
    ) {
        $user = $userId ? User::find()->id($userId)->status(null)->one() : null;
        $this->user = $user instanceof User ? $user : null;
        $this->notes = Craft::t('element-relations', 'replace-revision-notes', ['from' => (string)$from, 'to' => (string)$to]);
    }

    /**
     * Plans a new replacement: the usages of `$from` (optionally only in some relating elements), grouped by owner.
     * @param list<int>|null $onlySourceIds top-level owners as listed in the field; null = everywhere
     * @param int|null $onlySiteId only the usages in this site; null = every site
     */
    public static function plan(ReplacementRecord $operation, ElementInterface $from, ElementInterface $to, ?array $onlySourceIds, ?int $onlySiteId = null): self
    {
        $rows = (new Query())
            ->select(['sourceElementId' => 'cache.sourceElementId', 'sourceSiteId' => 'cache.sourceSiteId', 'sourcePrimaryOwnerId' => 'cache.sourcePrimaryOwnerId', 'fieldId' => 'cache.fieldId', 'type' => 'cache.type'])
            ->distinct()
            ->from(['cache' => '{{%elementrelations_cache}}'])
            // not in trashed elements (they are no usages until restored)
            ->innerJoin(['sources' => Table::ELEMENTS], '[[sources.id]] = [[cache.sourceElementId]]')
            ->where(['cache.targetElementId' => $from->id, 'sources.dateDeleted' => null])
            ->andWhere(['not', ['cache.sourceElementId' => null]]) // non-element usages are reported at the end
            ->andWhere($onlySourceIds === null ? [] : \internetztube\elementRelations\ElementRelations::idIn('cache.sourcePrimaryOwnerId', $onlySourceIds)) // as text: at most 65,535 parameters
            ->andFilterWhere(['cache.sourceSiteId' => $onlySiteId])
            ->orderBy(['cache.sourcePrimaryOwnerId' => SORT_ASC, 'cache.sourceSiteId' => SORT_ASC, 'cache.sourceElementId' => SORT_ASC])
            ->all();
        /** @var list<array{sourceElementId: int|string, sourceSiteId: int|string, sourcePrimaryOwnerId: int|string|null, fieldId: int|string|null, type: string}> $rows */
        /** @var array<string, OwnerGroup> $groups */
        $groups = [];
        foreach ($rows as $row) {
            $sourceId = (int)$row['sourceElementId'];
            $siteId = (int)$row['sourceSiteId'];
            $ownerId = $row['sourcePrimaryOwnerId'] !== null ? (int)$row['sourcePrimaryOwnerId'] : $sourceId;
            $groups["$ownerId:$siteId"] ??= ['ownerId' => $ownerId, 'siteId' => $siteId, 'rows' => []];
            $groups["$ownerId:$siteId"]['rows'][] = [
                'sourceElementId' => $sourceId,
                'sourceSiteId' => $siteId,
                'sourcePrimaryOwnerId' => $row['sourcePrimaryOwnerId'] !== null ? (int)$row['sourcePrimaryOwnerId'] : null,
                'fieldId' => $row['fieldId'] !== null ? (int)$row['fieldId'] : null,
                'type' => (string)$row['type'],
            ];
        }
        $operation->plan = json_encode(array_values($groups)) ?: null;
        $operation->save(false);
        return new self($operation, $from, $to, $operation->userId !== null ? (int)$operation->userId : null);
    }

    /** Continues a planned replacement from its record (the next batch of the queue job). */
    public static function resume(ReplacementRecord $operation, ElementInterface $from, ElementInterface $to): self
    {
        $run = new self($operation, $from, $to, $operation->userId !== null ? (int)$operation->userId : null);
        $state = $operation->getState();
        $result = $operation->getResult();
        $run->owners = $operation->getRevisions();
        $run->replaced = (int)($result['replaced'] ?? 0);
        $run->skipped = $result['skipped'] ?? [];
        $run->saved = array_fill_keys(array_map(fn(mixed $id): int => is_numeric($id) ? (int)$id : 0, (array)($state['saved'] ?? [])), true);
        /** @var array<int, list<string>> $pending */
        $pending = (array)($state['pending'] ?? []);
        $run->pending = $pending;
        $run->processed = is_numeric($state['processed'] ?? null) ? (int)$state['processed'] : 0;
        $run->total = is_numeric($state['total'] ?? null) ? (int)$state['total'] : null;
        $run->doneOwners = array_fill_keys(array_map(fn(mixed $id): int => is_numeric($id) ? (int)$id : 0, (array)($state['doneOwners'] ?? [])), true);
        return $run;
    }

    /** @return list<OwnerGroup> */
    public static function groups(ReplacementRecord $operation): array
    {
        /** @var list<OwnerGroup> $groups */
        $groups = $operation->getPlan();
        return $groups;
    }

    /**
     * Rewrites one owner group: every relating element of that owner in that site, then the owner's revision.
     * @param OwnerGroup $group
     */
    public function processGroup(array $group): void
    {
        $elementsService = Craft::$app->getElements();
        $ownerId = $group['ownerId'];
        $siteId = $group['siteId'];
        $bySource = [];
        foreach ($group['rows'] as $row) {
            $bySource[$row['sourceElementId']][] = $row;
        }
        $types = ReplaceService::types([$ownerId, ...array_keys($bySource)]);
        /** @var OwnerRevisions|null $owner */
        $owner = null;
        $ownerChanged = false;
        $savedHere = [];
        $groupDone = false; // a usage (this owner in this site) counts once, however many fields or blocks point at the target

        foreach ($bySource as $id => $rows) {
            $element = ReplaceService::load($types[$id] ?? null, $id, $siteId);
            if (!$element) {
                continue;
            }
            if ($this->user && !$elementsService->canSave($element, $this->user)) {
                $this->skipped[] = ReplaceService::label($element, $this->user) . ' (' . Craft::t('element-relations', 'replace-no-permission') . ')';
                continue;
            }
            $owner ??= ReplaceService::ownerInfo($ownerId === $id ? $element : ReplaceService::load($types[$ownerId] ?? null, $ownerId, $siteId), $ownerId, $siteId, $this->userId, $this->from, $this->to);

            $elementChanges = [];
            $alreadyDone = false;
            foreach ($rows as $row) {
                $field = $row['fieldId'] ? Craft::$app->getFields()->getFieldById($row['fieldId']) : null;
                $field = $field instanceof Field ? $field : null;
                if ($row['fieldId'] && !$field) {
                    $this->pending[$id][] = ReplaceService::label($element, $this->user);
                    continue;
                }
                if ($field && !ReplaceService::canEditField($element, $field, $this->user)) {
                    $this->skipped[] = ReplaceService::label($element, $this->user, $field) . ' (' . Craft::t('element-relations', 'replace-no-permission') . ')';
                    continue;
                }
                $preHadTo = in_array($this->to->id, ExtractorService::targetIds($element, $field), true);
                $before = $field ? ReplaceService::serializedFieldValue($field, $element) : null;
                $ok = $field ? ReplaceService::replaceInField($field, $element, $this->from, $this->to) : ReplaceService::replaceInElement($element, $this->from, $this->to);
                if ($ok) {
                    $elementChanges[] = ['elementId' => $id, 'siteId' => $siteId, 'ownerId' => $ownerId, 'fieldId' => $field ? (int)$field->id : null, 'preHadTo' => $preHadTo, 'before' => $before];
                } elseif ($this->isReplaced($element, $field)) {
                    $alreadyDone = true; // done by this operation before its job broke off (its change row exists)
                } else {
                    $this->pending[$id][] = ReplaceService::label($element, $this->user, $field);
                }
            }
            if (!$elementChanges) {
                if ($alreadyDone) {
                    $groupDone = true;
                    $this->saved[$id] = true;
                }
                continue;
            }
            $element->setRevisionNotes($this->notes);
            if ($this->userId) {
                $element->setRevisionCreatorId($this->userId);
            }
            if (!$elementsService->saveElement($element)) {
                $this->skipped[] = ReplaceService::label($element, $this->user) . ' (' . implode(' ', $element->getFirstErrors()) . ')';
                continue;
            }
            $groupDone = true;
            $this->saved[$id] = true;
            $savedHere[] = $id;
            $ownerChanged = true;
            $fresh = ReplaceService::load($types[$id] ?? null, $id, $siteId);
            foreach ($elementChanges as $change) {
                $field = $change['fieldId'] ? Craft::$app->getFields()->getFieldById($change['fieldId']) : null;
                $change['after'] = $field instanceof Field && $fresh ? ReplaceService::serializedFieldValue($field, $fresh) : null;
                $this->operation->addChange($change); // right away: what is saved can always be rolled back
            }
        }

        if ($groupDone) {
            $this->replaced++;
            $this->doneOwners[$ownerId] = true;
        }
        $this->processed++;
        // the owner gets a revision of the replaced state (Craft makes none for a nested-only change, and skips one
        // when the previous revision is from the same second)
        if ($owner && $ownerChanged) {
            $owner['revisionAfterId'] = ReplaceService::guaranteeRevision($owner, $owner['revisionBeforeId'], $this->notes, $this->userId);
            $this->owners[] = $owner;
        }
        ReplaceService::refreshEverySite($savedHere, $types);
    }

    /** Saves the state between batches. */
    public function persist(): void
    {
        $this->operation->revisions = json_encode($this->owners) ?: null;
        $this->operation->result = json_encode(['replaced' => $this->replaced, 'skipped' => array_values(array_unique($this->skipped)), 'withoutRevision' => $this->withoutRevision()]) ?: null;
        $this->operation->state = json_encode(['saved' => array_keys($this->saved), 'pending' => $this->pending, 'processed' => $this->processed, 'total' => $this->total ??= count(self::groups($this->operation)), 'doneOwners' => array_keys($this->doneOwners)]) ?: null;
        $this->operation->save(false);
    }

    /**
     * Completes the replacement: usages no site could rewrite and plugin settings count as skipped, the operation is
     * marked applied, listeners hear about it.
     * @param list<int>|null $onlySourceIds as planned
     * @return array{replaced: int, skipped: list<string>, operationId: int|null, queued: bool, withoutRevision: int, denied: bool}
     */
    public function finish(?array $onlySourceIds): array
    {
        // measured, not tallied per site: the owners this run changed that no longer use it in the planned sites (see
        // ReplaceService::elementCount(); every site with a usage, or the one site of a replacement in one site); a
        // source deleted or edited by someone else meanwhile is not among them
        $done = array_keys($this->doneOwners);
        $sites = array_values(array_unique(array_map(fn(array $group): int => $group['siteId'], self::groups($this->operation))));
        $this->replaced = count($done) - ReplaceService::elementCount((int)$this->from->id, $done, $sites);
        // Data shared across sites (non-translatable fields, Formie forms) is swapped by whichever site got saved first;
        // the other sites then hold no reference anymore. So only elements saved in no site count as skipped.
        foreach ($this->pending as $id => $labels) {
            if (!isset($this->saved[$id])) {
                $this->skipped = [...$this->skipped, ...$labels];
            }
        }
        if ($onlySourceIds === null) {
            foreach ((new RelationsModel((int)$this->from->id, (int)$this->from->siteId))->getGlobalUsages() as $usage) {
                $this->skipped[] = $usage->label;
            }
        }
        $this->skipped = array_values(array_unique($this->skipped));
        $this->pending = [];
        $this->operation->status = ReplacementRecord::STATUS_APPLIED;
        $this->operation->plan = null; // done; the changes are what rollbacks need
        $this->persist();
        $this->operation->state = null;
        $this->operation->save(false);

        $result = ['replaced' => $this->replaced, 'skipped' => $this->skipped, 'operationId' => (int)$this->operation->id, 'queued' => false, 'withoutRevision' => $this->withoutRevision(), 'denied' => false];
        Craft::info(sprintf('Replaced #%d by #%d in %d elements (%d without revision)%s', $this->from->id, $this->to->id, $this->replaced, $result['withoutRevision'], $this->skipped ? '; skipped: ' . implode(', ', array_slice($this->skipped, 0, 50)) : ''), 'element-relations');
        if (Event::hasHandlers(ReplaceService::class, ReplaceService::EVENT_AFTER_REPLACE)) {
            Event::trigger(ReplaceService::class, ReplaceService::EVENT_AFTER_REPLACE, new ReplaceEvent(['from' => $this->from, 'to' => $this->to, 'sourceIds' => $onlySourceIds, 'result' => $result, 'operation' => $this->operation]));
        }
        return $result;
    }

    /** The element references the replacement and no longer the original (in this field). */
    private function isReplaced(ElementInterface $element, ?Field $field): bool
    {
        $targets = ExtractorService::targetIds($element, $field);
        return in_array($this->to->id, $targets, true) && !in_array($this->from->id, $targets, true);
    }

    private function withoutRevision(): int
    {
        return count(array_filter($this->owners, fn(array $owner): bool => $owner['support'] !== ReplaceService::REVISIONS_YES));
    }
}
