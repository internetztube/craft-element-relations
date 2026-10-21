<?php

declare(strict_types=1);

namespace internetztube\elementRelations\migrations;

use craft\db\Migration;

/**
 * Pro: replacements run in batches (elements with thousands of usages). Each operation keeps its plan (the usages
 * grouped by owner) and the state between batches; the JSON columns may hold megabytes (LONGTEXT on MySQL).
 */
class m261023_000000_replacement_batches extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%elementrelations_replacements}}';
        if (!$this->db->tableExists($table)) {
            return true;
        }
        foreach (['plan', 'state'] as $column) {
            if (!$this->db->columnExists($table, $column)) {
                $this->addColumn($table, $column, $this->longText()->null()->after('result'));
            }
        }
        foreach (['sourceIds', 'result'] as $column) {
            $this->alterColumn($table, $column, $this->longText()->null());
        }
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn('{{%elementrelations_replacements}}', 'plan');
        $this->dropColumn('{{%elementrelations_replacements}}', 'state');
        return true;
    }
}
