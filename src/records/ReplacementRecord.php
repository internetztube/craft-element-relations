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
 * @property string|null $changes JSON list of {elementId, siteId, fieldId, preHadTo, before, after}
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

    public static function tableName(): string
    {
        return '{{%elementrelations_replacements}}';
    }

    /** @return array<int, array{elementId: int, siteId: int, fieldId: int|null, preHadTo: bool, before: mixed, after: mixed}> */
    public function getChanges(): array
    {
        return json_decode($this->changes ?? '[]', true) ?: [];
    }

    public function getResult(): array
    {
        return json_decode($this->result ?? '[]', true) ?: [];
    }
}
