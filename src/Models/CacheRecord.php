<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Models;

use CraftCms\Cms\Shared\BaseModel;
use CraftCms\Cms\Shared\Concerns\HasUid;
use Override;

/**
 * One relation in the cache: the source element (in a site) uses the target element (in a site), through a field or
 * a feature (`type`). Extractors return unsaved instances of it; ExtractorService writes them in one insert.
 *
 * @property int $id
 * @property int|null $sourceElementId null for non-element usages (InterfaceGlobalUsage)
 * @property int|null $sourceSiteId
 * @property int|null $sourcePrimaryOwnerId
 * @property int $targetElementId
 * @property int $targetSiteId
 * @property int|null $fieldId
 * @property string $type
 * @property string|null $customFieldUid
 * @property string|null $globalKey
 */
class CacheRecord extends BaseModel
{
    use HasUid;

    public const TABLE = 'elementrelations_cache';

    /** Indexes for the lookups of elements with thousands of usages (database/migrations/Install.php). */
    public const INDEXES = [
        ['type', 'globalKey'],
        ['targetElementId', 'sourcePrimaryOwnerId', 'sourceSiteId'], // usages of an element, per owner and site
        ['targetElementId', 'targetSiteId'],
        ['sourcePrimaryOwnerId', 'sourceSiteId'], // the usage list joins by owner
        ['sourceElementId', 'sourceSiteId'], // refreshing an element's own relations
    ];

    public const TYPE_FIELD = 'field';
    public const TYPE_ELEMENT_USER_PHOTO = 'element-user-photo';
    /** non-element usage rows (no source element), see Extractors\InterfaceGlobalUsage */
    public const TYPE_GLOBAL_SEOMATIC = 'global-seomatic';

    /** The columns that identify a relation (everything but id, uid and the dates). */
    public const ROW_COLUMNS = ['sourceElementId', 'sourceSiteId', 'sourcePrimaryOwnerId', 'targetElementId', 'targetSiteId', 'fieldId', 'customFieldUid', 'type'];

    #[Override]
    protected $table = self::TABLE;

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'sourceElementId' => 'integer',
            'sourceSiteId' => 'integer',
            'sourcePrimaryOwnerId' => 'integer',
            'targetElementId' => 'integer',
            'targetSiteId' => 'integer',
            'fieldId' => 'integer',
        ];
    }

    /**
     * The identifying columns, each present (null when not set), in ROW_COLUMNS order.
     * @return array<string, mixed>
     */
    public function row(): array
    {
        $row = [];
        foreach (self::ROW_COLUMNS as $column) {
            $row[$column] = $this->getAttribute($column);
        }
        return $row;
    }
}
