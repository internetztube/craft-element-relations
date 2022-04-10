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
                'relations' => $this->mediumText(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, $table, ['elementId'], true);
            // Adding an index on a text field in mysql requires a length
            if (Craft::$app->db->getIsMysql()) {
                $this->createIndex(null, $table, ['relations(250)', 'dateUpdated']);
            } else {
                $this->createIndex(null, $table, ['relations', 'dateUpdated']);
            }
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
