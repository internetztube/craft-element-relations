<?php

namespace internetztube\elementRelations\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Table;
use internetztube\elementRelations\records\ElementRelationsRecord;

class m240601_132533_drop extends Migration
{
    public function safeUp()
    {
        if ($this->db->tableExists("{{%elementrelations}}")) {
            $this->dropTable("{{%elementrelations}}");
        }
        return true;
    }

    public function safeDown()
    {
        echo "m240601_132533_drop cannot be reverted.\n";
        return false;
    }
}
