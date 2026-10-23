<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use CraftCms\Cms\Support\Facades\Elements;
use Illuminate\Console\Command;
use Internetztube\ElementRelations\Models\Replacement;

/** Lists the latest replacements with their ids, for rollback. */
class HistoryCommand extends Command
{
    use CraftCommand;
    use ReplacesElements;

    protected $signature = 'element-relations:replace:history {--limit=20 : How many replacements to list}';

    protected $description = 'Lists the latest replacements with their ids.';

    /** @var list<string> */
    protected $aliases = ['element-relations/replace/history'];

    public function handle(): int
    {
        $this->requirePro();
        $rows = [];
        foreach (Replacement::query()->orderByDesc('dateCreated')->orderByDesc('id')->limit(max(1, (int)$this->option('limit')))->get() as $operation) {
            /** @var Replacement $operation */
            $label = function (int $id) use ($operation): string {
                $element = Elements::getElementById($id, null, $operation->siteId);
                return $element ? sprintf('%s (#%d)', $element, $id) : "#$id (deleted)";
            };
            $result = $operation->getResult();
            $rows[] = [
                (string)$operation->id,
                (string)$operation->dateCreated?->format('Y-m-d H:i:s'),
                $label($operation->fromId),
                $label($operation->toId),
                (string)($result['replaced'] ?? ''),
                $operation->status,
            ];
        }
        if (!$rows) {
            $this->line('No replacements yet.');
            return self::SUCCESS;
        }
        $this->table(['Id', 'Date (UTC)', 'Replaced', 'With', 'Elements', 'Status'], $rows);
        return self::SUCCESS;
    }
}
