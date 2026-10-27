<?php

declare(strict_types=1);

namespace internetztube\elementRelations\models;

use craft\base\Model;

class SettingsModel extends Model
{
    public int $bulkRefreshBatchSize = 2000;

    /**
     * Queue jobs a full rebuild of the cache runs at the same time (RebuildRelationsJob lanes). At most the number of queue
     * workers; fewer leaves workers free for other jobs while it runs.
     */
    public int $rebuildParallelJobs = 1;

    /** Pro: elements that live content uses can't be deleted or taken offline in the control panel (services/InUseGuard). */
    public bool $protectInUse = true;

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
            [['rebuildParallelJobs'], 'required'],
            [['rebuildParallelJobs'], 'integer', 'min' => 1, 'max' => 100],
        ];
    }
}
