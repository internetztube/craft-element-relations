<?php

declare(strict_types=1);

namespace internetztube\elementRelations\records;

use craft\db\ActiveRecord;

/**
 * Element Relations Cache Record
 * @property int|null $sourceElementId null for non-element usages (InterfaceGlobalUsage)
 * @property int|null $sourceSiteId
 * @property int|null $sourcePrimaryOwnerId
 * @property int $targetElementId
 * @property int $targetSiteId
 * @property int|null $fieldId
 * @property string $type
 * @property string|null $customFieldUid
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

    public static function tableName(): string
    {
        return "{{%elementrelations_cache}}";
    }

    /** @return list<string> */
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
