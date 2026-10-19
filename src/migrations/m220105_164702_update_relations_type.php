<?php

declare(strict_types=1);

namespace internetztube\elementRelations\migrations;

use craft\db\Migration;

class m220105_164702_update_relations_type extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%elementrelations_cache}}';
        $this->alterColumn($table, "relations", $this->mediumText());
        return true;
    }

    public function safeDown(): bool
    {
        echo "m220105_164702_update_relations_type cannot be reverted.\n";
        return false;
    }
}
