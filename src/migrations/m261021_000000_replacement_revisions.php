<?php

namespace internetztube\elementRelations\migrations;

use craft\db\Migration;

/** Pro: per replacement, the owners it changed and their revisions before/after (for "revert to revisions"). */
class m261021_000000_replacement_revisions extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%elementrelations_replacements}}';
        if ($this->db->tableExists($table) && !$this->db->columnExists($table, 'revisions')) {
            $this->addColumn($table, 'revisions', $this->longText()->null()->after('changes'));
        }
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn('{{%elementrelations_replacements}}', 'revisions');
        return true;
    }
}
