<?php

namespace internetztube\elementRelations\migrations;

use Craft;
use craft\db\Migration;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

class m220103_164702_install extends Migration
{
    public function safeUp()
    {
        $table = ElementRelationsCacheRecord::tableName();
        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'sourceElementId' => $this->integer()->notNull(),
            'sourceSiteId' => $this->integer()->notNull(),
            'sourcePrimaryOwnerId' => $this->integer()->notNull(),
            'targetElementId' => $this->integer()->notNull(),
            'targetSiteId' => $this->integer()->notNull(),
            'customFieldUid' => $this->string(),
            'fieldId' => $this->integer(),
            'type' => $this->string()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        Craft::$app->db->schema->refresh();

        // No foreign keys, since elements can be linked that are not there anymore, like in Redactor, CkEditor, ....
        return true;
    }

    public function safeDown()
    {
        $table = ElementRelationsCacheRecord::tableName();
        if ($this->db->tableExists($table)) {
            $this->dropTable($table);
            Craft::$app->db->schema->refresh();
        }
        return true;
    }
}
