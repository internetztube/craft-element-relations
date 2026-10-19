<?php

namespace internetztube\elementRelations\models;

use Craft;
use craft\base\Model;

class SettingsModel extends Model
{
    public int $bulkRefreshBatchSize = 2000;

    /** Feature flags; only meaningful in `config/element-relations.php`, see ElementRelations::features(). */
    public array $features = [];

    protected function defineRules(): array
    {
        return [
            [['bulkRefreshBatchSize'], 'required'],
            [['bulkRefreshBatchSize'], 'integer', 'min' => 1],
        ];
    }
}
