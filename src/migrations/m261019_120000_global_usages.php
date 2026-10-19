<?php

namespace internetztube\elementRelations\migrations;

use craft\db\Migration;
use internetztube\elementRelations\models\RelationsModel;

/**
 * Non-element usages (SEOmatic settings) become cache rows without a source element, so counts, sorting and the
 * "unused" filter read everything from one table.
 */
class m261019_120000_global_usages extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%elementrelations_cache}}';
        $this->alterColumn($table, 'sourceElementId', $this->integer()->null());
        $this->alterColumn($table, 'sourceSiteId', $this->integer()->null());
        $this->alterColumn($table, 'sourcePrimaryOwnerId', $this->integer()->null());
        $this->addColumn($table, 'globalKey', $this->string()->null()->after('type'));
        $this->createIndex(null, $table, ['type', 'globalKey']);
        $this->db->getSchema()->refresh();

        RelationsModel::refreshGlobalUsages();
        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%elementrelations_cache}}';
        $this->delete($table, ['sourceElementId' => null]);
        $this->dropColumn($table, 'globalKey');
        $this->alterColumn($table, 'sourceElementId', $this->integer()->notNull());
        $this->alterColumn($table, 'sourceSiteId', $this->integer()->notNull());
        $this->alterColumn($table, 'sourcePrimaryOwnerId', $this->integer()->notNull());
        return true;
    }
}
