<?php

namespace internetztube\elementRelations\migrations;

use Craft;
use craft\db\Migration;
use internetztube\elementRelations\records\ElementRelationsRecord;

/**
 * Element Relations Install Migration
 */
class Install extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @return boolean
     */
    public function safeUp(): bool
    {

        $table = ElementRelationsRecord::tableName();
        if (!$this->db->tableExists($table)) {
            $this->createTable($table, [
                'id' => $this->primaryKey(),
                'elementId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'resultHtml' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, $table, ['elementId','siteId'], true);

            $this->addForeignKey(null, $table, 'elementId', '{{%elements}}', 'id', 'CASCADE');
            $this->addForeignKey(null, $table, 'siteId', '{{%sites}}', 'id', 'CASCADE');

            // Refresh the db schema caches
            Craft::$app->db->schema->refresh();
        }

        return true;
    }

    /**
     * @return boolean
     * @throws \Throwable
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists($table);

        return true;
    }
}
