<?php

namespace internetztube\elementRelations\records;

use craft\db\ActiveRecord;

/**
 * ElementRelationsRecord
 *
 * @property int $id ID
 * @property string $type ENUM Type
 * @property int $sourceElementId Source Element Id
 * @property int $sourceSiteId Source Site Id
 * @property int $sourceUserId Source User Id
 * @property int $targetElementId Target Element Id
 * @property int $targetSiteId Target Site Id
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
