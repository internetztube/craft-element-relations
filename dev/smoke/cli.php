<?php
/**
 * Pro console commands (console/controllers/ReplaceController): dry run, replace, a CSV mapping, history, rollback.
 * Creates "CLI …" entries and deletes them again.
 */

use craft\elements\Entry;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\records\ReplacementRecord;
use yii\console\ExitCode;

return function (array $ctx): void {
    $app = Craft::$app;
    $elements = $app->getElements();
    $plugins = $app->getPlugins();
    $editionBefore = ElementRelations::getInstance()->edition;
    $flush = function (): void {
        Craft::$app->trigger(yii\base\Application::EVENT_AFTER_REQUEST);
        Craft::$app->getQueue()->run();
    };
    /** Runs `php craft <route> <args>` in a subprocess, like a deploy script, and returns [exit code, output]. */
    $run = function (string $route, array $args = []): array {
        $command = [...phpCommand(), CRAFT_BASE_PATH . '/craft', $route, '--color=0'];
        foreach ($args as $name => $value) {
            $command[] = is_int($name) ? (string)$value : ($value === true ? "--$name" : "--$name=$value");
        }
        exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $lines, $code);
        return [$code, implode("\n", $lines)];
    };
    $section = $app->getEntries()->getSectionByHandle('pages');
    $make = function (string $title, array $fields = []) use ($section, $elements): Entry {
        $entry = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => $title]);
        $entry->setFieldValues($fields);
        $elements->saveElement($entry) or throw new RuntimeException(json_encode($entry->getErrors()));
        return $entry;
    };
    foreach (Entry::find()->title('CLI *')->status(null)->all() as $old) {
        $elements->deleteElement($old, true);
    }
    $old1 = $make('CLI old 1');
    $old2 = $make('CLI old 2');
    $new1 = $make('CLI new 1');
    $new2 = $make('CLI new 2');
    $source = $make('CLI source', ['relatedEntries' => [$old1->id, $old2->id]]);
    $other = $make('CLI other source', ['relatedEntries' => [$old1->id]]);
    $flush();
    $ids = fn(Entry $e) => array_map('intval', Entry::find()->id($e->id)->one()->getFieldValue('relatedEntries')->ids());

    $plugins->switchEdition('element-relations', 'lite');
    $flush(); // writes the project config, which the subprocess reads
    [$code, $out] = $run('element-relations/replace', [$old1->id, $new1->id]);
    check($code !== ExitCode::OK && str_contains($out, 'Pro edition') && $ids($source) === [$old1->id, $old2->id], "Lite: the command fails (exit code $code) and changes nothing");
    $plugins->switchEdition('element-relations', 'pro');
    $flush();

    [$code, $out] = $run('element-relations/replace', [$old1->id, $new1->id, 'dry-run' => true]);
    check($code === ExitCode::OK && str_contains($out, 'would change 2 elements') && str_contains($out, 'CLI source') && str_contains($out, 'CLI other source'), 'dry run lists both usages (' . trim(strtok($out, "\n")) . ')');
    check($ids($source) === [$old1->id, $old2->id], 'dry run changes nothing');

    [$code, $out] = $run('element-relations/replace', [$old1->id, $new1->id, 'usages' => (string)$source->id]);
    $flush();
    check($code === ExitCode::OK && $ids($source) === [$new1->id, $old2->id] && $ids($other) === [$old1->id], '--usages limits it to the given element (' . trim($out) . ')');
    preg_match('/rollback (\d+)/', $out, $m);
    $operation = ReplacementRecord::findOne((int)($m[1] ?? 0));
    check($operation && $operation->status === 'applied' && $operation->userId === null, 'it is logged in the history, without a user, with the rollback command printed');

    [$code, $out] = $run('element-relations/replace/history', ['limit' => 5]);
    check($code === ExitCode::OK && str_contains($out, 'CLI old 1') && str_contains($out, (string)$operation?->id), 'history lists it');

    [$code, $out] = $run('element-relations/replace/rollback', [(int)$operation?->id]);
    $flush();
    check($code === ExitCode::OK && $ids($source) === [$old1->id, $old2->id] && str_contains($out, 'Rolled back'), 'rollback restores it (' . trim($out) . ')');

    // a CSV mapping, as after merging duplicates: header, comment, two pairs
    $csv = tempnam(sys_get_temp_dir(), 'er') . '.csv';
    file_put_contents($csv, "from,to\n# merged duplicates\n{$old1->id},{$new1->id}\n{$old2->id},{$new2->id}\n");
    [$code, $out] = $run('element-relations/replace/map', [$csv, 'dry-run' => true]);
    check($code === ExitCode::OK && str_contains($out, '2 of 2 pairs checked') && $ids($source) === [$old1->id, $old2->id], 'map --dry-run checks both pairs, changes nothing');
    [$code, $out] = $run('element-relations/replace/map', [$csv]);
    $flush();
    check($code === ExitCode::OK && str_contains($out, '2 of 2 pairs replaced') && $ids($source) === [$new1->id, $new2->id] && $ids($other) === [$new1->id], 'map replaces every pair everywhere');
    unlink($csv);
    [$code] = $run('element-relations/replace', [$old1->id, $old1->id]);
    check($code !== ExitCode::OK, 'replacing an element by itself fails');

    // what a script gets wrong: each answers with its own exit code and a message, and changes nothing
    $before = [$ids($source), $ids($other)];
    [$code, $out] = $run('element-relations/replace', [$new1->id, $old1->id, 'usages' => ',']);
    check($code === ExitCode::USAGE && str_contains($out, '--usages lists no ids'), "--usages without ids: exit code $code");
    [$code, $out] = $run('element-relations/replace/map', ['/nonexistent/pairs.csv']);
    check($code === ExitCode::NOINPUT && str_contains($out, 'Cannot read'), "map of a missing file: exit code $code");
    $empty = tempnam(sys_get_temp_dir(), 'er') . '.csv';
    file_put_contents($empty, "from,to
# nothing yet
");
    [$code, $out] = $run('element-relations/replace/map', [$empty]);
    unlink($empty);
    check($code === ExitCode::DATAERR && str_contains($out, 'no "from,to" pairs'), "map of a file without pairs: exit code $code");
    [$code, $out] = $run('element-relations/replace', [$new1->id, $old1->id, 'site' => 'nosuchsite']);
    check($code !== ExitCode::OK && str_contains($out, 'Unknown site'), "an unknown site handle: exit code $code");
    [$code, $out] = $run('element-relations/replace', [$new1->id, $old1->id, 'site' => (string)$app->getSites()->getPrimarySite()->id, 'usages' => (string)$source->id, 'dry-run' => true]);
    check($code === ExitCode::OK && str_contains($out, 'would change 1 element') && str_contains($out, 'CLI source') && !str_contains($out, 'CLI other source'), 'a site by id and --usages in a dry run: only that element is listed');
    [$code, $out] = $run('element-relations/replace/rollback', [999999]);
    check($code !== ExitCode::OK && str_contains($out, Craft::t('element-relations', 'rollback-not-possible')), "rolling back an unknown replacement: exit code $code");
    [$code, $out] = $run('element-relations/replace/rollback', [(int)$operation?->id]);
    check($code !== ExitCode::OK && str_contains($out, Craft::t('element-relations', 'rollback-not-possible')), "rolling back one already rolled back: exit code $code");
    [$code, $out] = $run('element-relations/replace/resume', [(int)$operation?->id]);
    check($code !== ExitCode::OK && str_contains($out, Craft::t('element-relations', 'resume-not-needed')), "resuming one that is not running: exit code $code");
    check([$ids($source), $ids($other)] === $before, 'none of them changed anything');

    // resume: a queued replacement whose job is gone continues from the command line
    $queued = internetztube\elementRelations\services\ReplaceService::replace($new1, $old1, [$other->id], queue: true);
    (new craft\db\Query())->createCommand()->delete(craft\db\Table::QUEUE)->execute(); // the job disappears
    [$code, $out] = $run('element-relations/replace/resume', [(int)$queued['operationId']]);
    $flush();
    check($code === ExitCode::OK && str_contains($out, Craft::t('element-relations', 'resume-done')) && $ids($other) === [$old1->id] && ReplacementRecord::findOne((int)$queued['operationId'])?->status === 'applied', 'resume queues the job again and it finishes (' . trim($out) . ')');

    // the options are per action; history before any replacement says there is none (in a transaction rolled back)
    [$controller] = $app->createController('element-relations/replace');
    check(in_array('usages', $controller->options('index'), true) && !in_array('usages', $controller->options('help'), true) && in_array('limit', $controller->options('history'), true), 'each command takes its own options only');
    $transaction = $app->getDb()->beginTransaction();
    ReplacementRecord::deleteAll();
    ob_start();
    $code = $controller->runAction('history');
    ob_end_clean();
    $transaction->rollBack();
    check($code === ExitCode::OK && ReplacementRecord::find()->exists(), 'history without any replacement says so and succeeds');

    // cleanup
    foreach (ReplacementRecord::find()->where(['fromId' => [$old1->id, $old2->id]])->all() as $record) {
        $record->delete();
    }
    foreach ([$source, $other, $old1, $old2, $new1, $new2] as $entry) {
        $elements->deleteElement($entry, true);
    }
    $plugins->switchEdition('element-relations', $editionBefore);
    $flush();
};
