<?php

namespace internetztube\elementRelations\migrations;

use craft\db\Migration;
use internetztube\elementRelations\records\ElementRelationsRecord;

class m220104_164702_simplify_cache_strategy extends Migration
{
    public function safeUp()
    {
        $table = ElementRelationsRecord::tableName();
        // drop previous index on 'siteId` which was built by the install so we can drop the column
        foreach (Craft::$app->db->getSchema()->findIndexes($table) as $name => $index) {
            if ($index['columns'] === ['siteId']) {
                $this->dropIndex($name, $table);
            }
        }
        $this->dropColumn($table, 'siteId');
        $this->dropColumn($table, 'markup');
        $this->createIndex(null, $table, ['relations', 'dateUpdated']);
        return true;
    }

    public function safeDown()
    {
        echo "m220104_132645_simplify_cache_strategy cannot be reverted.\n";
        return false;
    }
}
