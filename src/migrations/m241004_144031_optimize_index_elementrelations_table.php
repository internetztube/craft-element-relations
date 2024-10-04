<?php

namespace internetztube\elementRelations\migrations;

use Craft;
use craft\db\Migration;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

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

    private function _indexes(): array
    {
        return [
            [ElementRelationsCacheRecord::tableName(), ['sourceElementId', 'sourceSiteId'], false],
            [ElementRelationsCacheRecord::tableName(), ['targetElementId', 'targetSiteId'], false],
            [ElementRelationsCacheRecord::tableName(), ['sourcePrimaryOwnerId'], false],
        ];
    }
}
