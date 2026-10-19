<?php

namespace internetztube\elementRelations\migrations;

use craft\db\Migration;

/** Pro: log of replace operations, so they can be rolled back. */
class m261020_000000_replacements extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%elementrelations_replacements}}')) {
            Install::createReplacementsTable($this);
        }
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%elementrelations_replacements}}');
        return true;
    }
}
