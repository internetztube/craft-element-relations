<?php

namespace internetztube\elementRelations\migrations;

use Craft;
use craft\db\Migration;
use internetztube\elementRelations\records\ElementRelationsRecord;

class m220104_164702_simplify_cache_strategy extends Migration
{
    public function safeUp()
    {
        $table = ElementRelationsRecord::tableName();
        // drop previous foreign key on 'siteId` which was built by the install so we can drop the column
        foreach (Craft::$app->db->getSchema()->getTableForeignKeys($table) as $foreignKey) {
            if (in_array('siteId', $foreignKey->columnNames)) {
                $this->dropForeignKey($foreignKey->name, $table);
            }
        }

        // drop previous index on 'siteId` which was built by the install so we can drop the column
        foreach (Craft::$app->db->getSchema()->findIndexes($table) as $name => $index) {
            if ($index['columns'] === ['siteId']) {
                $this->dropIndex($name, $table);
            }
        }
        if (Craft::$app->db->columnExists($table, 'siteId')) {
            $this->dropColumn($table, 'siteId');
        }
        if (Craft::$app->db->columnExists($table, 'markup')) {
            $this->dropColumn($table, 'markup');
        }
        // Adding an index on a text field in mysql requires a length
        if (Craft::$app->db->getIsMysql()) {
            $this->createIndex(null, $table, ['relations(250)', 'dateUpdated']);
        } else {
            $this->createIndex(null, $table, ['relations', 'dateUpdated']);
        }
        return true;
    }

    public function safeDown()
    {
        echo "m220104_164702_simplify_cache_strategy cannot be reverted.\n";
        return false;
    }
}
