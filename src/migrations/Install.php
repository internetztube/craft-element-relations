<?php

declare(strict_types=1);

namespace internetztube\elementRelations\migrations;

use Craft;
use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
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
        foreach (m261024_000000_cache_indexes::INDEXES as $columns) {
            $this->createIndex(null, '{{%elementrelations_cache}}', $columns);
        }
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
            'sourceIds' => $migration->longText()->null(),
            'status' => $migration->string(20)->notNull(),
            'changes' => $migration->longText()->null(),
            'revisions' => $migration->longText()->null(),
            'result' => $migration->longText()->null(),
            'plan' => $migration->longText()->null(),
            'state' => $migration->longText()->null(),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);
        $migration->createIndex(null, '{{%elementrelations_replacements}}', ['dateCreated']);
        self::createReplacementChangesTable($migration);
    }

    /** One row per changed element and field of a replacement (see services\ReplaceRun), removed with it. */
    public static function createReplacementChangesTable(Migration $migration): void
    {
        $migration->createTable('{{%elementrelations_replacement_changes}}', [
            'id' => $migration->primaryKey(),
            'operationId' => $migration->integer()->notNull(),
            'elementId' => $migration->integer()->notNull(),
            'siteId' => $migration->integer()->notNull(),
            'ownerId' => $migration->integer()->notNull(),
            'fieldId' => $migration->integer()->null(),
            'preHadTo' => $migration->boolean()->notNull()->defaultValue(false),
            'before' => $migration->longText()->null(), // JSON of the field value before
            'after' => $migration->longText()->null(), // JSON of the field value after
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);
        $migration->createIndex(null, '{{%elementrelations_replacement_changes}}', ['operationId', 'ownerId', 'siteId']);
        $migration->addForeignKey(null, '{{%elementrelations_replacement_changes}}', ['operationId'], '{{%elementrelations_replacements}}', ['id'], 'CASCADE');
    }

    public function safeDown(): bool
    {
        // the changes first: their foreign key on the replacements would block dropping those
        $this->dropTableIfExists('{{%elementrelations_replacement_changes}}');
        $this->dropTableIfExists('{{%elementrelations_replacements}}');
        $table = '{{%elementrelations_cache}}';
        if ($this->db->tableExists($table)) {
            $this->dropTable($table);
            Craft::$app->db->schema->refresh();
        }
        return true;
    }
}
