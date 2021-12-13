<?php

namespace internetztube\elementRelations\records;

use craft\db\ActiveRecord;

/**
 * EntryCountRecord
 *
 * @property int         $id                ID
 * @property int         $elementId         Entry ID
 * @property int         $siteId            Site ID
 * @property string      $relations         Related Entry IDs
 * @property string      $resultHtml        Resulting HTML
 * @property string      $dateCreated       Create Timestamp
 * @property string      $dateUpdated       Update Timestamp
 *
 */
class ElementRelationsRecord extends ActiveRecord
{
    // Public Static Methods
    // =========================================================================

     /**
     * @inheritdoc
     *
     * @return string the table name
     */
    public static function tableName(): string
    {
        return '{{%elementrelations}}';
    }
}
