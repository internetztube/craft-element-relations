<?php

declare(strict_types=1);

namespace internetztube\elementRelations\migrations;

use craft\db\Migration;

/**
 * m241004_144031_optimize_index_elementrelations_table migration.
 */
class m241004_144031_optimize_index_elementrelations_table extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        foreach ($this->_indexes() as $index) {
            $this->createIndexIfMissing(...$index);
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        foreach ($this->_indexes() as $index) {
            $this->dropIndexIfExists(...$index);
        }
        return true;
    }

    /** @return list<array{string, list<string>, bool}> table, columns, unique */
    private function _indexes(): array
    {
        return [
            ['{{%elementrelations_cache}}', ['sourceElementId', 'sourceSiteId'], false],
            ['{{%elementrelations_cache}}', ['targetElementId', 'targetSiteId'], false],
            ['{{%elementrelations_cache}}', ['sourcePrimaryOwnerId'], false],
        ];
    }
}
