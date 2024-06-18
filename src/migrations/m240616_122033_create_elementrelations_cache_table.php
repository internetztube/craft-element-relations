<?php

namespace internetztube\elementRelations\migrations;

use craft\db\Migration;
use craft\db\Table;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

class m240616_122033_create_elementrelations_cache_table extends Migration
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

        // No foreign keys, since elements can be linked that are not there anymore, like in Redactor, CkEditor, ....
        return true;
    }

    public function safeDown()
    {
        echo "m240616_122033_create_elementrelations_cache_table cannot be reverted.\n";
        return false;
    }
}
