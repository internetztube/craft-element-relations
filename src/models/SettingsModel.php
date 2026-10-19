<?php

declare(strict_types=1);

namespace internetztube\elementRelations\models;

use Craft;
use craft\base\Model;

class SettingsModel extends Model
{
    public int $bulkRefreshBatchSize = 2000;

    /** Pro: replace operations touching more elements than this run as a queue job instead of in the request. */
    public int $replaceSyncLimit = 25;

    /**
     * Feature flags; only meaningful in `config/element-relations.php`, see ElementRelations::features().
     * @var array<string, mixed>
     */
    public array $features = [];

    /** @return list<array<int|string, mixed>> */
    protected function defineRules(): array
    {
        return [
            [['bulkRefreshBatchSize'], 'required'],
            [['bulkRefreshBatchSize'], 'integer', 'min' => 1],
            [['replaceSyncLimit'], 'integer', 'min' => 0],
        ];
    }
}
