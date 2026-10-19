<?php

namespace internetztube\elementRelations\migrations;

use Craft;
use craft\db\Migration;

class Install extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%elementrelations_cache}}', [
            'id' => $this->primaryKey(),
            // null for non-element usages (plugin settings), see InterfaceGlobalUsage
            'sourceElementId' => $this->integer()->null(),
            'sourceSiteId' => $this->integer()->null(),
            'sourcePrimaryOwnerId' => $this->integer()->null(),
            'targetElementId' => $this->integer()->notNull(),
            'targetSiteId' => $this->integer()->notNull(),
            'customFieldUid' => $this->string(),
            'fieldId' => $this->integer(),
            'type' => $this->string()->notNull(),
            'globalKey' => $this->string()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%elementrelations_cache}}', ['type', 'globalKey']);
        // No foreign keys, since elements can be linked that are not there anymore, like in Redactor, CkEditor, ....
        return true;
    }

    public function safeDown()
    {
        $table = '{{%elementrelations_cache}}';
        if ($this->db->tableExists($table)) {
            $this->dropTable($table);
            Craft::$app->db->schema->refresh();
        }
        return true;
    }
}
