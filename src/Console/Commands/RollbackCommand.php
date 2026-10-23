<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use Internetztube\ElementRelations\Http\Controllers\ReplaceController;
use Internetztube\ElementRelations\Models\Replacement;
use Internetztube\ElementRelations\Services\ReplaceService;

use function CraftCms\Cms\t;

/**
 * Rolls a replacement back (see the history for ids): swaps the replacement back, or with --revisions reverts the
 * changed entries to their revision from before the replacement.
 */
class RollbackCommand extends Command
{
    use CraftCommand;
    use ReplacesElements;

    protected $signature = 'element-relations:replace:rollback
        {operationId : The replacement\'s id}
        {--revisions : Roll back by reverting entries to their revision from before the replacement}';

    protected $description = 'Rolls a replacement back.';

    /** @var list<string> */
    protected $aliases = ['element-relations/replace/rollback'];

    public function handle(): int
    {
        $this->requirePro();
        $operation = Replacement::find((int)$this->argument('operationId'));
        if (!$operation instanceof Replacement || $operation->status !== Replacement::STATUS_APPLIED) {
            $this->error(t('rollback-not-possible', category: 'element-relations'));
            return self::FAILURE;
        }
        $result = ReplaceService::rollback($operation, $this->option('revisions') ? ReplaceService::ROLLBACK_REVISIONS : ReplaceService::ROLLBACK_SWAP);
        $this->info(ReplaceController::rollbackMessage($result['restored'], $result['reverted'], $result['skipped']));
        return self::SUCCESS;
    }
}
