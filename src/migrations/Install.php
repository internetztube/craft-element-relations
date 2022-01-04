<?php

namespace internetztube\elementRelations\migrations;

use Craft;
use craft\db\Migration;
use internetztube\elementRelations\records\ElementRelationsRecord;

class Install extends Migration
{
    public function safeUp()
    {
        $table = ElementRelationsRecord::tableName();
        if (!$this->db->tableExists($table)) {
            $this->createTable($table, [
                'id' => $this->primaryKey(),
                'elementId' => $this->integer()->notNull(),
                'relations' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, $table, ['elementId'], true);
            $this->addForeignKey(null, $table, 'elementId', '{{%elements}}', 'id', 'CASCADE');

            // Refresh the db schema caches
            Craft::$app->db->schema->refresh();
        }

        return true;
    }

    public function safeDown()
    {
        $table = ElementRelationsRecord::tableName();
        if ($this->db->tableExists($table)) {
            $this->dropTable($table);
            Craft::$app->db->schema->refresh();
        }
        return true;
    }
}
