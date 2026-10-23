<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;

/**
 * Replaces element references from the command line, for deploy scripts and content migrations.
 *
 *     php craft element-relations/replace 12 34                     # replace #12 with #34 everywhere
 *     php craft element-relations/replace 12 34 --usages=56,78      # only in these relating elements
 *     php craft element-relations/replace 12 34 --dry-run           # list what would change
 */
class ReplaceCommand extends Command
{
    use CraftCommand;
    use ReplacesElements;

    protected $signature = 'element-relations:replace
        {from : Id of the element to replace}
        {to : Id of the replacement}
        {--usages= : Only in these relating elements (comma-separated ids, as listed in the field)}
        {--site= : The site of the elements (handle or id; default: the primary site)}
        {--dry-run : List what would change, change nothing}';

    protected $description = 'Replaces an element with another one of the same type, everywhere or in the given usages.';

    /** @var list<string> */
    protected $aliases = ['element-relations/replace', 'element-relations/replace/index'];

    public function handle(): int
    {
        $this->requirePro();
        $usages = $this->option('usages');
        $usageIds = is_string($usages) ? array_values(array_filter(array_map('intval', explode(',', $usages)))) : null;
        if ($usageIds === []) {
            $this->error('--usages lists no ids.');
            return self::INVALID;
        }
        return $this->replaceOne((int)$this->argument('from'), (int)$this->argument('to'), $usageIds, (bool)$this->option('dry-run')) ? self::SUCCESS : self::FAILURE;
    }
}
