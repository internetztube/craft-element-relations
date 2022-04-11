<?php

namespace internetztube\elementRelations\migrations;

use craft\db\Migration;
use internetztube\elementRelations\records\ElementRelationsRecord;

class m220105_164702_update_relations_type extends Migration
{
    public function safeUp()
    {
        $table = ElementRelationsRecord::tableName();
        $this->alterColumn($table, 'relations', $this->mediumText());
        return true;
    }

    public function safeDown()
    {
        echo "m220105_164702_update_relations_type cannot be reverted.\n";
        return false;
    }
}
