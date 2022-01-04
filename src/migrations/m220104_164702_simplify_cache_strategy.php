<?php

namespace internetztube\elementRelations\migrations;

use craft\db\Migration;
use internetztube\elementRelations\records\ElementRelationsRecord;

class m220104_164702_simplify_cache_strategy extends Migration
{
    public function safeUp()
    {
        $table = ElementRelationsRecord::tableName();
        $this->dropColumn($table, 'siteId');
        $this->dropColumn($table, 'markup');
        $this->dropForeignKey($table, 'siteId');
        return true;
    }

    public function safeDown()
    {
        echo "m220104_132645_simplify_cache_strategy cannot be reverted.\n";
        return false;
    }
}
