<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use Internetztube\ElementRelations\Models\Replacement;
use Internetztube\ElementRelations\Services\ReplaceService;

use function CraftCms\Cms\t;

/**
 * Continues a replacement or rollback whose queue job is gone (a worker died, the job was deleted): it is queued again
 * and continues where it got.
 */
class ResumeCommand extends Command
{
    use CraftCommand;
    use ReplacesElements;

    protected $signature = 'element-relations:replace:resume {operationId : The replacement\'s id (see the history)}';

    protected $description = 'Continues a replacement or rollback whose queue job is gone.';

    /** @var list<string> */
    protected $aliases = ['element-relations/replace/resume'];

    public function handle(): int
    {
        $this->requirePro();
        $operation = Replacement::find((int)$this->argument('operationId'));
        if (!$operation instanceof Replacement || !in_array($operation->status, [Replacement::STATUS_QUEUED, Replacement::STATUS_ROLLBACK_QUEUED], true)) {
            $this->error(t('resume-not-needed', category: 'element-relations'));
            return self::FAILURE;
        }
        ReplaceService::resume($operation);
        $this->info(t('resume-done', category: 'element-relations') . ' Run the queue (php artisan queue:work) or let your queue worker pick it up.');
        return self::SUCCESS;
    }
}
