<?php

declare(strict_types=1);

namespace internetztube\elementRelations\migrations;

use craft\db\Migration;

/**
 * Indexes for the lookups of elements with thousands of usages. Installs from before this release could miss all of
 * them: Install.php did not create the indexes of m241004_144031 (Craft marks every migration as applied on install).
 */
class m261024_000000_cache_indexes extends Migration
{
    /** @var list<list<string>> */
    public const INDEXES = [
        ['targetElementId', 'sourcePrimaryOwnerId', 'sourceSiteId'], // usages of an element, per owner and site
        ['targetElementId', 'targetSiteId'],
        ['sourcePrimaryOwnerId', 'sourceSiteId'], // the usage list joins by owner
        ['sourceElementId', 'sourceSiteId'], // refreshing an element's own relations
    ];

    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%elementrelations_cache}}')) {
            return true;
        }
        foreach (self::INDEXES as $columns) {
            $this->createIndexIfMissing('{{%elementrelations_cache}}', $columns);
        }
        return true;
    }

    public function safeDown(): bool
    {
        return true; // the indexes do no harm
    }
}
