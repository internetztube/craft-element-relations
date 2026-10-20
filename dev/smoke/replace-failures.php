<?php
/**
 * Pro: what goes wrong while replacing and rolling back, and that each case ends in a state the user can act on
 * (applied and undoable, or untouched), never "queued" forever: wrong input, a job that breaks off, a user who is gone
 * by the time the job runs, a source that fails validation, an element deleted before the rollback.
 */

use craft\elements\Entry;
use craft\elements\User;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\jobs\ReplaceElementJob;
use internetztube\elementRelations\jobs\RollbackJob;
use internetztube\elementRelations\records\ReplacementRecord;
use internetztube\elementRelations\services\ReplaceService;
use yii\base\Event;

return function (array $ctx): void {
    $app = Craft::$app;
    $elements = $app->getElements();
    $plugins = $app->getPlugins();
    $editionBefore = ElementRelations::getInstance()->edition;
    $plugins->switchEdition('element-relations', 'pro');
    $session = $app->getUser();
    $identityBefore = $session->getIdentity();
    $flush = function (): void {
        Craft::$app->trigger(yii\base\Application::EVENT_AFTER_REQUEST);
        Craft::$app->getQueue()->run();
    };
    $section = $app->getEntries()->getSectionByHandle('pages');
    $make = function (string $title, array $fields = []) use ($section, $elements): Entry {
        $entry = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => $title]);
        $entry->setFieldValues($fields);
        $elements->saveElement($entry) or throw new RuntimeException(json_encode($entry->getErrors()));
        return $entry;
    };
    foreach (Entry::find()->title('Failing *')->status(null)->all() as $old) {
        $elements->deleteElement($old, true);
    }
    $from = $make('Failing from');
    $to = $make('Failing to');
    $source = $make('Failing source', ['relatedEntries' => [$from->id]]);
    $flush();
    $ids = fn(Entry $e): array => array_map('intval', Entry::find()->id($e->id)->status(null)->one()->getFieldValue('relatedEntries')->ids());
    $record = fn(int $id): ?ReplacementRecord => ReplacementRecord::findOne($id);
    /** A throwaway admin, the acting user of a replacement whose job runs later. */
    $tempAdmin = function (): User {
        $user = new User(['username' => 'failing-' . uniqid(), 'email' => uniqid('failing-') . '@example.test', 'admin' => true]);
        Craft::$app->getElements()->saveElement($user, false) or throw new RuntimeException(json_encode($user->getErrors()));
        return $user;
    };

    // --- wrong input
    $threw = fn(callable $fn): bool => (function () use ($fn): bool { try { $fn(); return false; } catch (InvalidArgumentException) { return true; } })();
    check($threw(fn() => ReplaceService::replace($from, $from)), 'replacing an element by itself is refused');
    check($threw(fn() => ReplaceService::replace($from, $ctx['asset'])), 'replacing an entry by an asset is refused');
    check(ReplaceService::replace($from, $to, [])['replaced'] === 0 && $ids($source) === [$from->id], '"none selected" replaces nothing, never everywhere');
    $ghost = new ReplacementRecord(['fromId' => $from->id, 'toId' => $to->id, 'siteId' => $from->siteId, 'userId' => 999999, 'status' => ReplacementRecord::STATUS_QUEUED]);
    check(ReplaceService::replace($from, $to, null, $ghost)['denied'] === true && $ids($source) === [$from->id], 'an operation of a user who no longer exists is denied, nothing changes');

    // --- progress, as the CLI and migrations report it
    $steps = [];
    $done = ReplaceService::replace($from, $to, [$source->id], progress: function (float $p) use (&$steps): void { $steps[] = $p; });
    check($done['replaced'] === 1 && $steps && $steps[0] === 0.0 && end($steps) === 1.0, 'replace reports its progress from 0 to 1 (' . implode(', ', $steps) . ')');
    $applied = $record((int)$done['operationId']);
    check($threw(fn() => ReplaceService::queueRollback(new ReplacementRecord(['status' => ReplacementRecord::STATUS_FAILED]))), 'a failed operation cannot be queued for rollback');
    ReplaceService::rollback($applied);
    $flush();
    check($threw(fn() => ReplaceService::rollback($record((int)$applied->id))), 'an operation rolled back cannot be rolled back again');

    // --- the acting user is gone when the rollback runs
    $admin = $tempAdmin();
    $session->setIdentity($admin);
    $done = ReplaceService::replace($from, $to, [$source->id]);
    $flush();
    $elements->deleteElement($admin, true);
    check($threw(fn() => ReplaceService::rollback($record((int)$done['operationId']))) && $ids($source) === [$to->id], 'a rollback by a user who is gone is refused, the replacement stays');
    $session->setIdentity($identityBefore);
    ReplaceService::rollback($record((int)$done['operationId']));
    $flush();

    // --- a job that breaks off: what it did stays, the operation is applied and undoable, the error is reported
    $queued = ReplaceService::replace($from, $to, [$source->id], queue: true);
    $boom = function (craft\events\ModelEvent $event) use ($source): void {
        if ((int)$event->sender->id === (int)$source->id) {
            throw new RuntimeException('disk full');
        }
    };
    Event::on(Entry::class, Entry::EVENT_BEFORE_SAVE, $boom);
    $app->getQueue()->run();
    Event::off(Entry::class, Entry::EVENT_BEFORE_SAVE, $boom);
    $stopped = $record((int)$queued['operationId']);
    $message = implode(' ', $stopped?->getResult()['skipped'] ?? []);
    check($stopped?->status === ReplacementRecord::STATUS_APPLIED && str_contains($message, 'disk full'), 'a job that breaks off ends applied, with the error in its result: ' . $message);
    (new craft\db\Query())->createCommand()->delete(craft\db\Table::QUEUE)->execute(); // the failed job

    // --- a source that fails validation: skipped with Craft's message, the rest goes on
    $invalid = function (yii\base\ModelEvent $event) use ($source): void {
        if ((int)$event->sender->id === (int)$source->id) {
            $event->sender->addError('title', 'Title is taken.');
            $event->isValid = false;
        }
    };
    Event::on(Entry::class, Entry::EVENT_BEFORE_VALIDATE, $invalid);
    $result = ReplaceService::replace($from, $to, [$source->id]);
    Event::off(Entry::class, Entry::EVENT_BEFORE_VALIDATE, $invalid);
    check($result['replaced'] === 0 && str_contains(implode(' ', $result['skipped']), 'Title is taken.') && $ids($source) === [$from->id], 'a source that fails validation is skipped with its message (' . implode(' ', $result['skipped']) . ')');

    // --- the job runs after the replacement was deleted: it is marked as such, nothing changes
    $queued = ReplaceService::replace($from, $to, [$source->id], queue: true);
    $doomedTo = $to;
    $elements->deleteElement($doomedTo, true);
    $app->getQueue()->run();
    $invalidRun = $record((int)$queued['operationId']);
    check($invalidRun?->status === ReplacementRecord::STATUS_APPLIED && str_contains(implode(' ', $invalidRun->getResult()['skipped'] ?? []), Craft::t('element-relations', 'replace-invalid')) && $ids($source) === [$from->id], 'a queued replacement whose replacement was deleted ends without changing anything');
    $to = $make('Failing to');
    (new ReplaceElementJob(['operationId' => (int)$queued['operationId']]))->execute($app->getQueue()); // not queued anymore: nothing
    (new ReplaceElementJob(['operationId' => 999999]))->execute($app->getQueue());
    check($ids($source) === [$from->id], 'a job for an operation that is done or gone does nothing');

    // --- a rollback job whose user is gone: the operation goes back to applied, nothing is rolled back
    $done = ReplaceService::replace($from, $to, [$source->id]);
    $flush();
    $admin = $tempAdmin();
    $session->setIdentity($admin);
    ReplaceService::queueRollback($record((int)$done['operationId']));
    $session->setIdentity($identityBefore);
    $elements->deleteElement($admin, true);
    $app->getQueue()->run();
    check($record((int)$done['operationId'])?->status === ReplacementRecord::STATUS_APPLIED && $ids($source) === [$to->id], 'a rollback job whose user is gone leaves the replacement applied, to be rolled back again');

    // --- a rollback job that breaks off: applied again, can be rolled back later
    ReplaceService::queueRollback($record((int)$done['operationId']));
    Event::on(Entry::class, Entry::EVENT_BEFORE_SAVE, $boom);
    $app->getQueue()->run();
    Event::off(Entry::class, Entry::EVENT_BEFORE_SAVE, $boom);
    (new craft\db\Query())->createCommand()->delete(craft\db\Table::QUEUE)->execute();
    check($record((int)$done['operationId'])?->status === ReplacementRecord::STATUS_APPLIED, 'a rollback job that breaks off ends applied again');
    $pending = $done; // still applied: the source points at $to
    (new RollbackJob(['operationId' => 999999]))->execute($app->getQueue());

    // --- a changed element deleted before the rollback: reported, the rest is rolled back
    $second = $make('Failing second source', ['relatedEntries' => [$from->id]]);
    $flush();
    $done = ReplaceService::replace($from, $to, [$second->id]);
    $flush();
    $elements->deleteElement($second, true);
    $back = ReplaceService::rollback($record((int)$done['operationId']));
    check(str_contains(implode(' ', $back['skipped']), Craft::t('element-relations', 'rollback-gone', ['id' => $second->id])), 'a changed element deleted since is reported by the rollback (' . implode(' ', $back['skipped']) . ')');

    // --- a rollback whose source fails validation: reported with the message
    Event::on(Entry::class, Entry::EVENT_BEFORE_VALIDATE, $invalid);
    $back = ReplaceService::rollback($record((int)$pending['operationId']) ?? throw new RuntimeException('no operation'));
    Event::off(Entry::class, Entry::EVENT_BEFORE_VALIDATE, $invalid);
    check(str_contains(implode(' ', $back['skipped']), 'Title is taken.') && $ids($source) === [$to->id], 'a rollback that fails validation reports it and leaves the element as it is');

    // --- the plan is fixed when it is queued: a source deleted before the job runs is passed over, one changed since
    // (it no longer uses the element) is reported as not replaced
    $gone = $make('Failing gone source', ['relatedEntries' => [$from->id]]);
    $changedSince = $make('Failing changed source', ['relatedEntries' => [$from->id]]);
    $flush();
    $queued = ReplaceService::replace($from, $to, [$gone->id, $changedSince->id], queue: true);
    $elements->deleteElement($gone, true);
    $changedSince->setFieldValue('relatedEntries', []);
    $elements->saveElement($changedSince) or throw new RuntimeException('save failed');
    $app->getQueue()->run();
    $ran = $record((int)$queued['operationId']);
    $skipped = implode(' ', $ran?->getResult()['skipped'] ?? []);
    check($ran?->status === ReplacementRecord::STATUS_APPLIED && ($ran->getResult()['replaced'] ?? null) === 0 && str_contains($skipped, 'Failing changed source') && !str_contains($skipped, 'Failing gone source'), "a queued plan: a source deleted since is passed over, one changed since is reported ($skipped)");
    $elements->deleteElement($changedSince, true);

    // --- a user who may not save the element: the rollback leaves it as it is and says why, in either mode
    $reset = Entry::find()->id($source->id)->status(null)->one();
    $reset->setFieldValue('relatedEntries', [$from->id]);
    $elements->saveElement($reset) or throw new RuntimeException('save failed');
    $flush();
    $editor = User::find()->username('jonas')->one() ?? throw new RuntimeException('Run the demo seed first (user jonas)');
    $byEditor = function (string $mode) use ($from, $to, $source, $session, $identityBefore, $editor, $record, $flush): array {
        $done = ReplaceService::replace($from, $to, [$source->id]);
        $flush();
        $session->setIdentity($editor);
        $back = ReplaceService::rollback($record((int)$done['operationId']), $mode);
        $session->setIdentity($identityBefore);
        $flush();
        return [$done, $back];
    };
    $noPermission = Craft::t('element-relations', 'replace-no-permission');
    foreach ([ReplaceService::ROLLBACK_SWAP, ReplaceService::ROLLBACK_REVISIONS] as $mode) {
        [$done, $back] = $byEditor($mode);
        check(str_contains(implode(' ', $back['skipped']), $noPermission) && $back['restored'] === 0 && $ids($source) === [$to->id], "rollback ($mode) by a user who may not save the element: skipped with the reason, nothing changes (" . implode(' ', $back['skipped']) . ')');
        check($record((int)$done['operationId'])?->status === ReplacementRecord::STATUS_APPLIED && str_starts_with(internetztube\elementRelations\controllers\ReplaceController::rollbackMessage(0, 0, $back['skipped']), Craft::t('element-relations', 'rollback-nothing')), "rollback ($mode) that could do nothing: the replacement stays applied, so someone who may can roll it back");
        ReplaceService::rollback($record((int)$done['operationId']));
        $flush();
    }

    // --- "revert to revisions" when the revision from before is gone: swapped back instead
    $done = ReplaceService::replace($from, $to, [$source->id]);
    $flush();
    $revisionIds = array_filter(array_column(json_decode((string)$record((int)$done['operationId'])->revisions, true) ?: [], 'revisionBeforeId'));
    check($revisionIds !== [], 'the replacement noted the revision from before');
    foreach (Entry::find()->revisions()->id($revisionIds)->status(null)->all() as $revision) {
        $elements->deleteElement($revision, true);
    }
    // and from the console, without a user: the revisions are made in the name of the oldest admin
    $session->setIdentity(null);
    $back = ReplaceService::rollback($record((int)$done['operationId']), ReplaceService::ROLLBACK_REVISIONS);
    $session->setIdentity($identityBefore);
    $flush();
    check($back['restored'] === 1 && $ids($source) === [$from->id], 'revert to revisions without the revision from before swaps back instead (' . json_encode($back) . ')');
    $done = ReplaceService::replace($from, $to, [$source->id]);
    $flush();
    $session->setIdentity(null);
    $back = ReplaceService::rollback($record((int)$done['operationId']), ReplaceService::ROLLBACK_REVISIONS);
    $session->setIdentity($identityBefore);
    $flush();
    $creators = array_map(intval(...), (new craft\db\Query())->select(['creatorId'])->from(craft\db\Table::REVISIONS)->where(['canonicalId' => $source->id])->orderBy(['num' => SORT_DESC])->limit(2)->column());
    check($ids($source) === [$from->id] && in_array(ReplaceService::anyAdminId(), $creators, true), 'revert to revisions from the console: done in the name of the oldest admin (' . json_encode([$back, $creators, ReplaceService::anyAdminId()]) . ')');

    // resuming an operation that is not running queues nothing
    $jobs = fn(): int => (int)(new craft\db\Query())->from(craft\db\Table::QUEUE)->count();
    $queuedBefore = $jobs();
    ReplaceService::resume($record((int)$done['operationId']));
    check($jobs() === $queuedBefore, 'resuming an operation that is not running queues nothing');

    // cleanup
    foreach (ReplacementRecord::find()->where(['fromId' => [$from->id, $to->id]])->all() as $operation) {
        $operation->delete();
    }
    foreach (Entry::find()->title('Failing *')->status(null)->all() as $entry) {
        $elements->deleteElement($entry, true);
    }
    $plugins->switchEdition('element-relations', $editionBefore);
    $flush();
};
