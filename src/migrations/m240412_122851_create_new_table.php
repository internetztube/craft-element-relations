<?php

namespace internetztube\elementRelations\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Table;
use internetztube\elementRelations\records\ElementRelationsRecord;

class m240412_122851_create_new_table extends Migration
{
    public function safeUp()
    {
        $table = ElementRelationsRecord::tableName();
        if (!$this->db->tableExists($table)) {
            $this->createTable($table, [
                'id' => $this->primaryKey(),
                'type' => $this->string(32),
                'sourceElementId' => $this->integer()->null(),
                'sourceSiteId' => $this->integer()->null(),
                'targetElementId' => $this->integer()->notNull(),
                'targetSiteId' => $this->integer()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, $table, ['targetElementId', 'targetSiteId', 'type'], false);
            $this->addForeignKey(null, $table, 'sourceElementId', Table::ELEMENTS, 'id', 'CASCADE');
            $this->addForeignKey(null, $table, 'targetElementId', Table::ELEMENTS, 'id', 'CASCADE');
            $this->addForeignKey(null, $table, 'sourceSiteId', Table::SITES, 'id', 'CASCADE');
            $this->addForeignKey(null, $table, 'targetSiteId', Table::SITES, 'id', 'CASCADE');

            // Refresh the db schema caches
            Craft::$app->db->schema->refresh();
        }

        return true;
    }

    public function safeDown()
    {
        echo "m240412_122851_create_new_table cannot be reverted.\n";
        return false;
    }
}
