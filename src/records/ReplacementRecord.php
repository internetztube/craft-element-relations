<?php

declare(strict_types=1);

namespace internetztube\elementRelations\records;

use craft\db\ActiveRecord;

/**
 * Pro: one "replace X with Y" operation, its changes and whether it was rolled back (see services\ReplaceService).
 *
 * @property int $id
 * @property int $fromId
 * @property int $toId
 * @property int $siteId
 * @property int|null $userId
 * @property string|null $sourceIds JSON list of the owner ids the operation was limited to (null = everywhere)
 * @property string $status
 * @property string|null $changes JSON list of {elementId, siteId, ownerId, fieldId, preHadTo, before, after}
 * @property string|null $revisions JSON list of the changed owners {ownerId, siteId, support, revisionBeforeId, revisionAfterId}
 * @property string|null $result JSON {replaced, skipped, restored}
 * @property string|null $plan JSON list of the owner groups a batched run works through, see ReplaceRun / RollbackRun
 * @property string|null $state JSON state of a batched run between its batches
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 *
 * @phpstan-type Change array{elementId: int, siteId: int, ownerId?: int, fieldId: int|null, preHadTo: bool, before: mixed, after?: mixed}
 * @phpstan-type OwnerRevisions array{ownerId: int, siteId: int, support: string, revisionBeforeId: int|null, revisionAfterId?: int|null}
 */
class ReplacementRecord extends ActiveRecord
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_ROLLED_BACK = 'rolledBack';
    /** a rollback waiting for its queue job (RollbackJob) */
    public const STATUS_ROLLBACK_QUEUED = 'rollbackQueued';
    /** a queued replacement that could not run (its user is gone or may no longer replace) */
    public const STATUS_FAILED = 'failed';

    public static function tableName(): string
    {
        return '{{%elementrelations_replacements}}';
    }

    public const CHANGES_TABLE = '{{%elementrelations_replacement_changes}}';

    /**
     * The changes, one per element and field, in the order they were made (rows of CHANGES_TABLE; operations from
     * before that table keep theirs as JSON in `changes`).
     * @return list<Change>
     */
    public function getChanges(): array
    {
        if ($this->changes !== null) {
            /** @var list<Change> $legacy */
            $legacy = self::decode($this->changes);
            return $legacy;
        }
        $changes = [];
        foreach ((new \craft\db\Query())->from(self::CHANGES_TABLE)->where(['operationId' => $this->id])->orderBy(['id' => SORT_ASC])->each(1000) as $row) {
            /** @var array{elementId: int|string, siteId: int|string, ownerId: int|string, fieldId: int|string|null, preHadTo: bool|int|string, before: string|null, after: string|null} $row */
            $changes[] = [
                'elementId' => (int)$row['elementId'],
                'siteId' => (int)$row['siteId'],
                'ownerId' => (int)$row['ownerId'],
                'fieldId' => $row['fieldId'] !== null ? (int)$row['fieldId'] : null,
                'preHadTo' => (bool)$row['preHadTo'],
                'before' => $row['before'] !== null ? json_decode($row['before'], true) : null,
                'after' => $row['after'] !== null ? json_decode($row['after'], true) : null,
            ];
        }
        return $changes;
    }

    /** How many elements (per site) the operation changed, without loading the changes. */
    public function getChangedElementCount(): int
    {
        if ($this->changes !== null) {
            return count(array_unique(array_map(fn(array $change): string => $change['elementId'] . ':' . $change['siteId'], $this->getChanges())));
        }
        $distinct = (new \craft\db\Query())->select(['elementId', 'siteId'])->distinct()->from(self::CHANGES_TABLE)->where(['operationId' => $this->id]);
        return (int)(new \craft\db\Query())->from(['changes' => $distinct])->count();
    }

    /**
     * Records one change (right after the element was saved, so a crash cannot lose it).
     * @param Change $change
     */
    public function addChange(array $change): void
    {
        \craft\helpers\Db::insert(self::CHANGES_TABLE, [
            'operationId' => $this->id,
            'elementId' => $change['elementId'],
            'siteId' => $change['siteId'],
            'ownerId' => $change['ownerId'] ?? $change['elementId'],
            'fieldId' => $change['fieldId'],
            'preHadTo' => $change['preHadTo'],
            'before' => json_encode($change['before']) ?: null,
            'after' => json_encode($change['after'] ?? null) ?: null,
        ]);
    }

    /** @return list<OwnerRevisions> */
    public function getRevisions(): array
    {
        /** @var list<OwnerRevisions> $revisions */
        $revisions = self::decode($this->revisions);
        return $revisions;
    }

    /** Whether "revert to revisions" can do more than swapping back: at least one owner has a revision from before. */
    public function canRevertToRevisions(): bool
    {
        return (bool)array_filter($this->getRevisions(), fn(array $owner): bool => !empty($owner['revisionBeforeId']));
    }

    /** @return array{replaced?: int, skipped?: list<string>, withoutRevision?: int, restored?: int, reverted?: int, rollbackMode?: string, rollbackSkipped?: list<string>} */
    public function getResult(): array
    {
        /** @var array{replaced?: int, skipped?: list<string>, withoutRevision?: int, restored?: int, reverted?: int, rollbackMode?: string, rollbackSkipped?: list<string>} $result */
        $result = self::decode($this->result);
        return $result;
    }

    /** @return list<array<string, mixed>> the owner groups of a batched run */
    public function getPlan(): array
    {
        /** @var list<array<string, mixed>> $plan */
        $plan = self::decode($this->plan);
        return $plan;
    }

    /** @return array<string, mixed> */
    public function getState(): array
    {
        /** @var array<string, mixed> $state */
        $state = self::decode($this->state);
        return $state;
    }

    /** @return array<mixed> */
    private static function decode(?string $json): array
    {
        $value = json_decode($json ?? '[]', true);
        return is_array($value) ? $value : [];
    }
}
