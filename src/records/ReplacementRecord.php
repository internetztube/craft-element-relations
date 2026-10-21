<?php

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
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class ReplacementRecord extends ActiveRecord
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_ROLLED_BACK = 'rolledBack';
    /** a queued replacement that could not run (its user is gone or may no longer replace) */
    public const STATUS_FAILED = 'failed';

    public static function tableName(): string
    {
        return '{{%elementrelations_replacements}}';
    }

    /** @return array<int, array{elementId: int, siteId: int, ownerId?: int, fieldId: int|null, preHadTo: bool, before: mixed, after: mixed}> */
    public function getChanges(): array
    {
        return json_decode($this->changes ?? '[]', true) ?: [];
    }

    /** @return array<int, array{ownerId: int, siteId: int, support: string, revisionBeforeId: int|null, revisionAfterId: int|null}> */
    public function getRevisions(): array
    {
        return json_decode($this->revisions ?? '[]', true) ?: [];
    }

    /** Whether "revert to revisions" can do more than swapping back: at least one owner has a revision from before. */
    public function canRevertToRevisions(): bool
    {
        return (bool)array_filter($this->getRevisions(), fn(array $owner) => !empty($owner['revisionBeforeId']));
    }

    public function getResult(): array
    {
        return json_decode($this->result ?? '[]', true) ?: [];
    }
}
