<?php

namespace internetztube\elementRelations\records;

use Craft;
use craft\db\ActiveRecord;

/**
 * Element Relations Cache Record
 * @property int $sourceElementId
 * @property int $sourceSiteId
 * @property int $sourcePrimaryOwnerId
 * @property int $targetElementId
 * @property int $targetSiteId
 * @property int $fieldId
 * @property string $type
 * @property string $customFieldUid
 * @property string|null $globalKey
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 *
 */
class ElementRelationsCacheRecord extends ActiveRecord
{

    public const TYPE_FIELD = "field";
    public const TYPE_ELEMENT_USER_PHOTO = "element-user-photo";
    /** non-element usage rows (no source element), see services\extractors\InterfaceGlobalUsage */
    public const TYPE_GLOBAL_SEOMATIC = "global-seomatic";

    public static function tableName()
    {
        return "{{%elementrelations_cache}}";
    }

    public function safeAttributes(): array
    {
        return [
            "sourceElementId",
            "sourceSiteId",
            "sourcePrimaryOwnerId",
            "targetElementId",
            "targetSiteId",
            "fieldId",
            "customFieldUid",
            "type",
            "globalKey",
        ];
    }

}
