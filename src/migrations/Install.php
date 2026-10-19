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
        self::createReplacementsTable($this);
        // No foreign keys, since elements can be linked that are not there anymore, like in Redactor, CkEditor, ....
        return true;
    }

    /** Pro: log of replace operations (see records\ReplacementRecord). */
    public static function createReplacementsTable(Migration $migration): void
    {
        $migration->createTable('{{%elementrelations_replacements}}', [
            'id' => $migration->primaryKey(),
            'fromId' => $migration->integer()->notNull(),
            'toId' => $migration->integer()->notNull(),
            'siteId' => $migration->integer()->notNull(),
            'userId' => $migration->integer()->null(),
            'sourceIds' => $migration->text()->null(),
            'status' => $migration->string(20)->notNull(),
            'changes' => $migration->longText()->null(),
            'result' => $migration->text()->null(),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);
        $migration->createIndex(null, '{{%elementrelations_replacements}}', ['dateCreated']);
    }

    public function safeDown()
    {
        $this->dropTableIfExists('{{%elementrelations_replacements}}');
        $table = '{{%elementrelations_cache}}';
        if ($this->db->tableExists($table)) {
            $this->dropTable($table);
            Craft::$app->db->schema->refresh();
        }
        return true;
    }
}
