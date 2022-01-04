<?php

namespace internetztube\elementRelations\records;

use craft\db\ActiveRecord;

/**
 * ElementRelationsRecord
 *
 * @property int $id ID
 * @property int $elementId Entry ID
 * @property string $relations Related Entry IDs
 * @property string $dateCreated Create Timestamp
 * @property string $dateUpdated Update Timestamp
 *
 */
class ElementRelationsRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%elementrelations}}';
    }
}
