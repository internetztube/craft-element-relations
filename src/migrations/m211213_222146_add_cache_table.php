<?php

namespace internetztube\elementRelations\migrations;

use Craft;
use craft\db\Migration;
use internetztube\elementRelations\records\ElementRelationsRecord;

/**
 * m211213_222146_add_cache_table migration.
 */
class m211213_222146_add_cache_table extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        $table = ElementRelationsRecord::tableName();
        if (!$this->db->tableExists($table)) {
            $this->createTable($table, [
                'id' => $this->primaryKey(),
                'elementId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'relations' => $this->text(),
                'resultHtml' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, $table, ['elementId', 'siteId'], true);

            $this->addForeignKey(null, $table, 'elementId', '{{%elements}}', 'id', 'CASCADE');
            $this->addForeignKey(null, $table, 'siteId', '{{%sites}}', 'id', 'CASCADE');

            // Refresh the db schema caches
            Craft::$app->db->schema->refresh();
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        echo "m211213_222146_add_cache_table cannot be reverted.\n";
        return false;
    }
}
