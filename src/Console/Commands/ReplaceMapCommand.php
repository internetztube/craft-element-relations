<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;

/**
 * Replaces many elements at once: a CSV file with one "from,to" pair of ids per line (a header line and lines starting
 * with # are skipped), e.g. the old and new ids after merging duplicate assets.
 *
 *     php craft element-relations/replace/map mapping.csv
 */
class ReplaceMapCommand extends Command
{
    use CraftCommand;
    use ReplacesElements;

    protected $signature = 'element-relations:replace:map
        {file : Path to the CSV file}
        {--site= : The site of the elements (handle or id; default: the primary site)}
        {--dry-run : List what would change, change nothing}';

    protected $description = 'Replaces many elements at once, from a CSV file of "from,to" id pairs.';

    /** @var list<string> */
    protected $aliases = ['element-relations/replace/map'];

    public function handle(): int
    {
        $this->requirePro();
        $file = $this->argument('file');
        $file = is_string($file) ? $file : '';
        if (!is_readable($file)) {
            $this->error("Cannot read $file.");
            return self::FAILURE;
        }
        $pairs = self::readPairs($file);
        if (!$pairs) {
            $this->error("$file has no \"from,to\" pairs of ids.");
            return self::FAILURE;
        }
        $dryRun = (bool)$this->option('dry-run');
        $failed = 0;
        foreach ($pairs as $line => [$from, $to]) {
            $this->line("Line $line:");
            $failed += $this->replaceOne($from, $to, null, $dryRun) ? 0 : 1;
        }
        $summary = sprintf('%d of %d pairs %s.', count($pairs) - $failed, count($pairs), $dryRun ? 'checked' : 'replaced');
        $failed ? $this->warn($summary) : $this->info($summary);
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int, array{int, int}> line number => [from, to] */
    private static function readPairs(string $file): array
    {
        $pairs = [];
        $handle = fopen($file, 'rb') ?: throw new \RuntimeException("Cannot read $file."); // checked readable before
        for ($line = 1; ($row = fgetcsv($handle, escape: '\\')) !== false; $line++) {
            $from = trim((string)($row[0] ?? ''));
            $to = trim((string)($row[1] ?? ''));
            if ($from === '' || str_starts_with($from, '#') || !ctype_digit($from) || !ctype_digit($to)) {
                continue; // empty, comment or header line
            }
            $pairs[$line] = [(int)$from, (int)$to];
        }
        fclose($handle);
        return $pairs;
    }
}
