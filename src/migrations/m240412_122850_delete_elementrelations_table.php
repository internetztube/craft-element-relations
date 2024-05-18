<?php

namespace internetztube\elementRelations\migrations;

use Craft;
use craft\db\Migration;
use internetztube\elementRelations\records\ElementRelationsRecord;

class m240412_122850_delete_elementrelations_table extends Migration
{
    public function safeUp()
    {
        $table = ElementRelationsRecord::tableName();
        if ($this->db->tableExists($table)) {
            $this->dropTable($table);
            Craft::$app->db->schema->refresh();
        }
        return true;
    }

    public function safeDown()
    {
        echo "m240412_122850_delete_elementrelations_table cannot be reverted.\n";
        return false;
    }
}
