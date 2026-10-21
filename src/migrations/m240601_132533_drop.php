<?php

declare(strict_types=1);

namespace internetztube\elementRelations\migrations;

use craft\db\Migration;

class m240601_132533_drop extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists("{{%elementrelations}}")) {
            $this->dropTable("{{%elementrelations}}");
        }
        return true;
    }

    public function safeDown(): bool
    {
        echo "m240601_132533_drop cannot be reverted.\n";
        return false;
    }
}
