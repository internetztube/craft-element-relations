<?php

declare(strict_types=1);

namespace internetztube\elementRelations\migrations;

use craft\db\Migration;

/**
 * Pro: the changes of a replacement as rows, written right after each element is saved (crash-safe, no growing JSON
 * for elements with tens of thousands of usages). Older operations keep theirs in `replacements.changes`.
 */
class m261025_000000_replacement_changes extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%elementrelations_replacements}}') && !$this->db->tableExists('{{%elementrelations_replacement_changes}}')) {
            Install::createReplacementChangesTable($this);
        }
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%elementrelations_replacement_changes}}');
        return true;
    }
}
