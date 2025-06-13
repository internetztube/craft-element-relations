<?php

namespace internetztube\elementRelations\models;

use Craft;
use craft\base\Model;

class SettingsModel extends Model
{
    public int $bulkRefreshBatchSize = 2000;

    protected function defineRules(): array
    {
        return [
            [['bulkRefreshBatchSize'], 'required'],
        ];
    }
}
