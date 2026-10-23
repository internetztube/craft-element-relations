<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations;

use CraftCms\Cms\Plugin\PluginSettings;
use Override;

/** The plugin settings: project config, overridden by `config/craft/element-relations.php`. */
class Settings extends PluginSettings
{
    /** Elements per queue job when the whole relations cache is rebuilt. */
    public int $bulkRefreshBatchSize = 2000;

    /** Elements that live content uses can't be deleted or taken offline in the control panel (Services\InUseGuard). */
    public bool $protectInUse = true;

    /**
     * Feature flags; only meaningful in the config file, see ElementRelations::features().
     * @var array<string, mixed>
     */
    public array $features = [];

    /** @return array<string, list<string>> */
    #[Override]
    public function getRules(): array
    {
        return [
            'bulkRefreshBatchSize' => ['required', 'integer', 'min:1'],
            'protectInUse' => ['boolean'],
        ];
    }
}
