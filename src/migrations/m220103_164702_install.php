<?php

namespace internetztube\elementRelations\migrations;

use Craft;
use craft\db\Migration;

class m220103_164702_install extends Migration
{
    public function safeUp()
    {
        $this->createTable("{{%elementrelations_cache}}", [
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
        if ($this->db->tableExists('{{%elementrelations_cache}}')) {
            $this->dropTable('{{%elementrelations_cache}}');
            Craft::$app->db->schema->refresh();
        }
        return true;
    }
}
