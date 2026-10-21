<?php

declare(strict_types=1);

namespace internetztube\elementRelations\console\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\console\Controller;
use craft\helpers\Console;
use internetztube\elementRelations\controllers\ReplaceController as WebReplaceController;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\elements\Usage;
use internetztube\elementRelations\records\ReplacementRecord;
use internetztube\elementRelations\services\ReplaceService;
use yii\console\ExitCode;

/**
 * Pro: replaces element references from the command line, for deploy scripts and content migrations. Runs right away
 * (no queue), without a user (so nothing is skipped for permissions), and is logged in the history like replacements
 * in the control panel, so it can be rolled back.
 *
 *     php craft element-relations/replace 12 34                     # replace #12 with #34 everywhere
 *     php craft element-relations/replace 12 34 --usages=56,78      # only in these relating elements
 *     php craft element-relations/replace 12 34 --dry-run           # list what would change
 *     php craft element-relations/replace/map mapping.csv           # one "from,to" pair of ids per line
 *     php craft element-relations/replace/rollback 1234 --revisions
 *     php craft element-relations/replace/history --limit=50
 */
class ReplaceController extends Controller
{
    /** @var string|null Only in these relating elements (comma-separated ids, as listed in the field) */
    public ?string $usages = null;

    /** @var string|null The site of the elements (handle or id; default: the primary site) */
    public ?string $site = null;

    /** @var bool List what would change, change nothing */
    public bool $dryRun = false;

    /** @var bool Roll back by reverting entries to their revision from before the replacement */
    public bool $revisions = false;

    /** @var int How many replacements the history lists */
    public int $limit = 20;

    /** @return list<string> */
    public function options($actionID): array
    {
        $own = match ($actionID) {
            'index' => ['usages', 'site', 'dryRun'],
            'map' => ['site', 'dryRun'],
            'rollback' => ['revisions'],
            'resume' => [],
            'history' => ['limit'],
            default => [],
        };
        return [...parent::options($actionID), ...$own];
    }

    public function beforeAction($action): bool
    {
        if (!ElementRelations::isPro()) {
            // an exception, not `return false`: scripts must see a failing exit code
            throw new \yii\console\Exception('Replacing needs the Pro edition of Element Relations.');
        }
        return parent::beforeAction($action);
    }

    /**
     * Replaces an element with another one of the same type, everywhere or in the given usages.
     *
     * @param int $from id of the element to replace
     * @param int $to id of the replacement
     */
    public function actionIndex(int $from, int $to): int
    {
        $usageIds = $this->usageIds();
        if ($usageIds === []) {
            $this->stderr("--usages lists no ids.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        return $this->replaceOne($from, $to, $usageIds) ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Replaces many elements at once: a CSV file with one "from,to" pair of ids per line (a header line and lines
     * starting with # are skipped), e.g. the old and new ids after merging duplicate assets.
     *
     * @param string $file path to the CSV file
     */
    public function actionMap(string $file): int
    {
        if (!is_readable($file)) {
            $this->stderr("Cannot read $file.\n", Console::FG_RED);
            return ExitCode::NOINPUT;
        }
        $pairs = self::readPairs($file);
        if (!$pairs) {
            $this->stderr("$file has no \"from,to\" pairs of ids.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }
        $failed = 0;
        foreach ($pairs as $line => [$from, $to]) {
            $this->stdout("Line $line: ", Console::FG_GREY);
            $failed += $this->replaceOne($from, $to, null) ? 0 : 1;
        }
        $this->stdout(sprintf("\n%d of %d pairs %s.\n", count($pairs) - $failed, count($pairs), $this->dryRun ? 'checked' : 'replaced'), $failed ? Console::FG_YELLOW : Console::FG_GREEN);
        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Rolls a replacement back (see the history for ids): swaps the replacement back, or with --revisions reverts the
     * changed entries to their revision from before the replacement.
     *
     * @param int $operationId the replacement's id
     */
    public function actionRollback(int $operationId): int
    {
        $operation = ReplacementRecord::findOne($operationId);
        if (!$operation || $operation->status !== ReplacementRecord::STATUS_APPLIED) {
            $this->stderr(Craft::t('element-relations', 'rollback-not-possible') . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $mode = $this->revisions ? ReplaceService::ROLLBACK_REVISIONS : ReplaceService::ROLLBACK_SWAP;
        $result = ReplaceService::rollback($operation, $mode);
        $this->stdout(WebReplaceController::rollbackMessage($result['restored'], $result['reverted'], $result['skipped']) . "\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Continues a replacement or rollback whose queue job is gone (a worker died, the job was deleted): it is queued
     * again and continues where it got.
     *
     * @param int $operationId the replacement's id (see the history)
     */
    public function actionResume(int $operationId): int
    {
        $operation = ReplacementRecord::findOne($operationId);
        if (!$operation || !in_array($operation->status, [ReplacementRecord::STATUS_QUEUED, ReplacementRecord::STATUS_ROLLBACK_QUEUED], true)) {
            $this->stderr(Craft::t('element-relations', 'resume-not-needed') . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        ReplaceService::resume($operation);
        $this->stdout(Craft::t('element-relations', 'resume-done') . " Run the queue (php craft queue/run) or let your queue worker pick it up.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Lists the latest replacements with their ids, for rollback.
     */
    public function actionHistory(): int
    {
        $rows = [];
        $elements = Craft::$app->getElements();
        /** @var list<ReplacementRecord> $operations */
        $operations = ReplacementRecord::find()->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])->limit(max(1, $this->limit))->all();
        foreach ($operations as $operation) {
            $label = function (int $id) use ($elements, $operation): string {
                $element = $elements->getElementById($id, null, (int)$operation->siteId);
                return $element ? sprintf('%s (#%d)', $element, $id) : "#$id (deleted)";
            };
            $result = $operation->getResult();
            $rows[] = [
                (string)$operation->id,
                $operation->dateCreated,
                $label((int)$operation->fromId),
                $label((int)$operation->toId),
                (string)($result['replaced'] ?? ''),
                $operation->status,
            ];
        }
        if (!$rows) {
            $this->stdout("No replacements yet.\n");
            return ExitCode::OK;
        }
        $this->table(['Id', 'Date (UTC)', 'Replaced', 'With', 'Elements', 'Status'], $rows);
        return ExitCode::OK;
    }

    /** @param list<int>|null $usageIds */
    private function replaceOne(int $fromId, int $toId, ?array $usageIds): bool
    {
        $siteId = $this->siteId();
        $elements = Craft::$app->getElements();
        $from = $elements->getElementById($fromId, null, $siteId);
        $to = $from ? $elements->getElementById($toId, $from::class, $siteId) : null;
        if (!$from || !$to || $from->id === $to->id) {
            $this->stderr(sprintf("#%d → #%d: %s\n", $fromId, $toId, Craft::t('element-relations', 'replace-invalid')), Console::FG_RED);
            return false;
        }
        if ($this->dryRun) {
            $this->listUsages($from, $to, $usageIds);
            return true;
        }
        $result = ReplaceService::replace($from, $to, $usageIds);
        $color = $result['skipped'] ? Console::FG_YELLOW : Console::FG_GREEN;
        $this->stdout(WebReplaceController::resultMessage($result, (string)$from, (string)$to), $color);
        $this->stdout($result['operationId'] ? sprintf(" (roll back: php craft element-relations/replace/rollback %d)\n", $result['operationId']) : "\n", Console::FG_GREY);
        return true;
    }

    /**
     * What a replacement would change: every relating element, its place, the field and the site.
     * @param list<int>|null $usageIds
     */
    private function listUsages(ElementInterface $from, ElementInterface $to, ?array $usageIds): void
    {
        $query = Usage::find()->allSites()->status(null)->drafts(null);
        Craft::configure($query, ['usesElement' => $from->id]); // behavior param, see ElementQueryBehavior
        if ($usageIds !== null) {
            $query->id($usageIds);
        }
        $rows = array_map(fn(ElementInterface $element): array => [
            (string)$element->id,
            (string)$element,
            strip_tags($element->getAttributeHtml(Usage::ATTR_WHERE)),
            strip_tags($element->getAttributeHtml(Usage::ATTR_FIELD)),
            strip_tags($element->getAttributeHtml(Usage::ATTR_SITE)), // every site it uses $from in
        ], $query->all());
        $this->stdout(sprintf("“%s” (#%d) → “%s” (#%d): would change %d %s.\n", $from, $from->id, $to, $to->id, count($rows), count($rows) === 1 ? 'element' : 'elements'), Console::FG_CYAN);
        if ($rows) {
            $this->table(['Id', 'Element', 'Where', 'Field', 'Site'], $rows);
        }
    }

    /** @return list<int>|null null = everywhere */
    private function usageIds(): ?array
    {
        if ($this->usages === null) {
            return null;
        }
        return array_values(array_filter(array_map('intval', explode(',', $this->usages))));
    }

    private function siteId(): int
    {
        $sites = Craft::$app->getSites();
        if ($this->site === null || $this->site === '') {
            return (int)$sites->getPrimarySite()->id;
        }
        $site = ctype_digit($this->site) ? $sites->getSiteById((int)$this->site) : $sites->getSiteByHandle($this->site);
        return $site ? (int)$site->id : throw new \InvalidArgumentException("Unknown site “{$this->site}”.");
    }

    /**
     * @return array<int, array{int, int}> line number => [from, to]
     */
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
