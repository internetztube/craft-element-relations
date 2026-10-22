<?php
/** Pro features (edition switched to Pro for this file): unused query param/source and "Replace everywhere". */

use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\elements\actions\ReplaceRelations;
use internetztube\elementRelations\models\RelationsModel;
use internetztube\elementRelations\services\ReplaceService;

return function (array $ctx): void {
    $elements = Craft::$app->getElements();
    $plugins = Craft::$app->getPlugins();
    $flush = function (): void {
        Craft::$app->trigger(yii\base\Application::EVENT_AFTER_REQUEST);
        Craft::$app->getQueue()->run();
    };
    // Element::sources() memoizes per process; the edition changes within this file, so clear it before each read
    $sourceKeys = function (): array {
        $memo = new ReflectionProperty(craft\base\Element::class, 'sources');
        $memo->setValue(null, []);
        return array_column(array_filter(Asset::sources('index'), fn($s) => isset($s['key'])), 'key');
    };

    $editionBefore = ElementRelations::getInstance()->edition;
    $plugins->switchEdition('element-relations', 'lite');
    $offersUsedRule = fn(): bool => in_array(internetztube\elementRelations\elements\conditions\UsedConditionRule::class, array_map(fn($rule) => $rule::class, Asset::createCondition()->getSelectableConditionRules()), true);
    check(!$offersUsedRule() && !in_array(ReplaceRelations::class, Asset::actions('*'), true) && !internetztube\elementRelations\elements\Usage::actions('*'), 'Lite: no “Used anywhere” filter, no Replace actions');
    $plugins->switchEdition('element-relations', 'pro');
    check($offersUsedRule(), 'Pro: the asset filter offers “Used anywhere”');
    check(!in_array('elementRelations:unused', $sourceKeys(), true), 'no extra “Unused” source: the filter works on top of every source');
    $actions = array_map(fn($a) => is_array($a) ? ($a['type'] ?? '') : (is_object($a) ? $a::class : $a), Asset::actions('*'));
    check(in_array(ReplaceRelations::class, $actions, true), 'Pro: asset index has the Replace action');

    // unused: the test asset is used (fields + SEOmatic global), a fresh asset is not
    $asset = $ctx['asset'];
    foreach (Asset::find()->filename('unused*')->all() as $old) { // from an aborted run
        $elements->deleteElement($old, true);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'er') . '.png';
    file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $fresh = new Asset(['tempFilePath' => $tmp, 'filename' => 'unused.png', 'newFolderId' => Craft::$app->getAssets()->getRootFolderByVolumeId($asset->volumeId)->id, 'volumeId' => $asset->volumeId, 'avoidFilenameConflicts' => true]);
    $fresh->setScenario(Asset::SCENARIO_CREATE);
    $elements->saveElement($fresh) or throw new RuntimeException(json_encode($fresh->getErrors()));
    $unusedIds = Asset::find()->unused()->status(null)->ids();
    check(in_array($fresh->id, $unusedIds, true) && !in_array($asset->id, $unusedIds, true), 'unused(): fresh asset listed, used asset not');
    check(!in_array($asset->id, Asset::find()->unused()->ids(), true), 'SEOmatic global settings count as a usage');
    check((int)(new craft\db\Query())->from('{{%elementrelations_cache}}')->where(['targetElementId' => $asset->id, 'sourceElementId' => null, 'type' => 'global-seomatic'])->count() >= 1, 'the SEOmatic global usage is a cache row without a source element');
    $condition = Asset::createCondition();
    $condition->addConditionRule(Craft::createObject(['class' => internetztube\elementRelations\elements\conditions\UsedConditionRule::class, 'value' => false]));
    $viaCondition = Asset::find()->status(null);
    $condition->modifyQuery($viaCondition);
    check($viaCondition->ids() === $unusedIds, 'the "Used anywhere" condition rule (off) matches unused()');
    check(in_array(internetztube\elementRelations\elements\conditions\UsedConditionRule::class, array_map(fn($r) => $r::class, Asset::createCondition()->getSelectableConditionRules()), true), 'the rule is offered in asset conditions (filter HUD, custom sources)');
    $sort = Craft::$app->getFields()->getFieldByHandle('relations')->getSortOption();
    $sorted = Asset::find()->status(null)->orderBy([$sort['orderBy'] => SORT_DESC])->ids();
    $usageCount = fn(int $id) => (new RelationsModel($id, Craft::$app->getSites()->getPrimarySite()->id))->getCountFast([]);
    check(array_search($asset->id, $sorted, true) < array_search($fresh->id, $sorted, true) && $usageCount($sorted[0]) >= $usageCount($asset->id), 'sorting by the field puts the most used asset first and the unused one after it');
    $category = Category::find()->title('Test Category')->one(); // relates to Page C, nothing relates to it
    check($category && in_array($category->id, Category::find()->unused()->status(null)->ids(), true), 'unused() works for categories (Test Category is a source, not a target)');

    // The sort and unused() check every listed element's usages. On a large, skewed cache table PostgreSQL expects
    // thousands of usages per element and, if the SQL lets it, hashes all of `elements` once per listed element: a
    // request that never finishes. With nested loops off it takes that plan whenever it can; the source element must
    // still be looked up per usage (by primary key; on the seed's tiny tables a filtered seq scan is cheaper), or in a
    // hashed subplan, which PostgreSQL builds once per query; never as the input of a join inside the per-element
    // subquery.
    if (Craft::$app->getDb()->getIsPgsql()) {
        $db = Craft::$app->getDb();
        $elementsTable = $db->getSchema()->getRawTableName(craft\db\Table::ELEMENTS);
        $sourceLookups = function (string $sql) use ($db, $elementsTable): array {
            $db->createCommand('SET enable_nestloop = off')->execute();
            try {
                $plan = json_decode((string)$db->createCommand('EXPLAIN (FORMAT JSON) ' . $sql)->queryScalar(), true);
            } finally {
                $db->createCommand('RESET enable_nestloop')->execute();
            }
            $scans = [];
            // JSON plans name a hashed subplan plainly ("SubPlan 2"); only the condition using it says "hashed SubPlan 2"
            preg_match_all('/hashed (SubPlan \d+)/', (string)json_encode($plan), $matches);
            $hashedNames = $matches[1];
            $walk = function (array $node, bool $hashed = false) use (&$walk, &$scans, $elementsTable, $hashedNames): void {
                $hashed = $hashed || in_array($node['Subplan Name'] ?? null, $hashedNames, true);
                if (($node['Relation Name'] ?? null) === $elementsTable && ($node['Alias'] ?? null) === 'sources') {
                    $scans[] = ($hashed ? 'hashed: ' : '') . trim($node['Node Type'] . ' ' . implode(' ', array_filter([$node['Index Cond'] ?? null, $node['Recheck Cond'] ?? null, $node['Filter'] ?? null])));
                }
                foreach ($node['Plans'] ?? [] as $child) {
                    $walk($child, $hashed);
                }
            };
            $walk($plan[0]['Plan']);
            return $scans;
        };
        $byPrimaryKey = fn(array $scans): bool => $scans && !array_filter($scans, fn(string $scan): bool => !str_starts_with($scan, 'hashed: ') && !str_contains($scan, 'sourcePrimaryOwnerId'));
        $sortScans = $sourceLookups(Asset::find()->status(null)->orderBy([$sort['orderBy'] => SORT_DESC])->limit(100)->createCommand()->getRawSql());
        $ok = $byPrimaryKey($sortScans);
        check($ok, 'PostgreSQL: sorting by the field looks up each usage\'s source on its own, never scans all elements per listed element' . ($ok ? '' : ': ' . json_encode($sortScans)));
        $unusedScans = $sourceLookups(Asset::find()->unused()->status(null)->createCommand()->getRawSql());
        $ok = $byPrimaryKey($unusedScans);
        check($ok, 'PostgreSQL: unused() looks up each usage\'s source on its own, never scans all elements per listed element' . ($ok ? '' : ': ' . json_encode($unusedScans)));
    }

    // replace: own "from" entry (Page C is used by every integration seed; replacing it would rewrite them all)
    $b = Entry::find()->title('Page B')->one();
    $section = Craft::$app->getEntries()->getSectionByHandle('pages');
    foreach (Entry::find()->title('Page via replace*')->status(null)->all() as $old) {
        $elements->deleteElement($old, true);
    }
    $c = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => 'Page via replace (from)']);
    $elements->saveElement($c) or throw new RuntimeException(json_encode($c->getErrors()));
    $target = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => 'Page via replace (target)']);
    $elements->saveElement($target) or throw new RuntimeException(json_encode($target->getErrors()));
    $source = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => 'Page via replace']);
    $source->setFieldValue('relatedEntries', [$b->id, $c->id]);
    $hasCk = (bool)Craft::$app->getFields()->getFieldByHandle('ckeditor');
    if ($hasCk) {
        $source->setFieldValue('ckeditor', '<p><a href="{entry:' . $c->id . ':url}">C</a> and <a href="{entry:' . $c->id . '@' . $c->siteId . ':url}">C again</a></p>');
    }
    $elements->saveElement($source) or throw new RuntimeException(json_encode($source->getErrors()));
    $other = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => 'Page via replace (other)']);
    $other->setFieldValue('relatedEntries', [$c->id]);
    $elements->saveElement($other) or throw new RuntimeException(json_encode($other->getErrors()));
    $flush();
    check(in_array('Page via replace', relatingTitles($c), true) && in_array('Page via replace (other)', relatingTitles($c), true), 'before: both sources relate to the "from" entry');

    // wizard: only the ticked usages; the ticked ids are owners (what the field lists), nested sources match through them
    $matrixField = Craft::$app->getFields()->getFieldByHandle('teasers');
    $nested = null;
    if ($matrixField instanceof \craft\fields\Matrix) {
        $blockType = $matrixField->getEntryTypes()[0];
        $nested = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => 'Page via replace (nested)']);
        $nested->setFieldValue('teasers', ['new1' => ['type' => $blockType->handle, 'fields' => ['relatedEntries' => [$c->id]]]]);
        $elements->saveElement($nested) or throw new RuntimeException(json_encode($nested->getErrors()));
        $flush();
        check(in_array('Page via replace (nested)', relatingTitles($c), true), 'nested: a Matrix block of the nested entry relates to the "from" entry');
        $viaOwner = ReplaceService::replace($c, $target, [$nested->id]);
        $flush();
        check($viaOwner['replaced'] === 1 && in_array('Page via replace (nested)', relatingTitles($target), true) && !in_array('Page via replace (nested)', relatingTitles($c), true), 'nested: selecting the owner replaces inside its Matrix block');
    }
    $subset = ReplaceService::replace($c, $target, [$other->id]);
    check(in_array('Page via replace (other)', relatingTitles($target), true), 'the cache is updated synchronously (before the queue runs)');
    $flush();
    check($subset['replaced'] === 1 && in_array('Page via replace', relatingTitles($c), true) && in_array('Page via replace (other)', relatingTitles($target), true), 'replace() limited to selected sources touches only those');
    $field = Craft::$app->getFields()->getFieldByHandle('relations');
    Craft::$app->getView()->clear();
    $input = withWebRequest(fn() => $field->getInputHtml($c->getFieldValue('relations'), $c));
    check(str_contains($input, 'data-element-relations-from="' . $c->id . '"'), 'Pro: the field tells the index which element its "Replace…" action replaces');
    check(in_array(internetztube\elementRelations\elements\actions\ReplaceUsages::class, internetztube\elementRelations\elements\Usage::actions('*'), true), 'Pro: the usage list offers "Replace…" for the ticked usages (an element action, so Craft shows checkboxes)');
    $rawBefore = $hasCk ? Entry::find()->id($source->id)->one()->getFieldValue('ckeditor')->getRawContent() : '';

    $result = ReplaceService::replace($c, $target);
    check($result['replaced'] === 1 && !$result['skipped'], 'replace() everywhere: exactly the remaining source was rewritten, nothing skipped (' . json_encode($result) . ')');
    $flush();
    $reloaded = Entry::find()->id($source->id)->one();
    $ids = array_map('intval', $reloaded->getFieldValue('relatedEntries')->ids());
    check($ids === [$b->id, $target->id], 'entries field: C replaced by the target, order kept, B untouched (' . implode(',', $ids) . ')');
    if ($hasCk) {
        $raw = $reloaded->getFieldValue('ckeditor')->getRawContent();
        check(!str_contains($raw, '{entry:' . $c->id) && substr_count($raw, '{entry:' . $target->id) === 2, "CKEditor: both reference tags point to the target (before: $rawBefore | after: $raw)");
    }
    check(!in_array('Page via replace', relatingTitles($c), true) && in_array('Page via replace', relatingTitles($target), true), 'cache follows: source now relates to the target, not to the "from" entry');
    $skipped = ReplaceService::replace($asset, $fresh)['skipped']; // round trip below restores the seeded content
    check((bool)array_filter($skipped, fn($s) => str_starts_with($s, 'SEOmatic global settings')), 'replacing the asset reports SEOmatic global settings as a manual change (' . implode(' | ', $skipped) . ')');
    $flush();
    ReplaceService::replace($fresh, $asset); // undo the asset swap
    $flush();

    // rollback edge cases on an entries field
    $both = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => 'Page via replace (both)']);
    $both->setFieldValue('relatedEntries', [$c->id, $target->id]);
    $elements->saveElement($both) or throw new RuntimeException(json_encode($both->getErrors()));
    $flush();
    $ids = fn(Entry $e) => array_map('intval', Entry::find()->id($e->id)->one()->getFieldValue('relatedEntries')->ids());
    $op = ReplaceService::replace($c, $target, [$both->id]);
    $flush();
    check($ids($both) === [$target->id], 'replacing in a field that already held the target leaves it once');
    $rb = ReplaceService::rollback(internetztube\elementRelations\records\ReplacementRecord::findOne($op['operationId']));
    $flush();
    check($rb['restored'] === 1 && $ids($both) === [$c->id, $target->id], 'rollback restores the exact previous value (both, in order)');
    $op = ReplaceService::replace($c, $target, [$both->id]);
    $flush();
    $edited = Entry::find()->id($both->id)->one();
    $edited->setFieldValue('relatedEntries', [$target->id, $b->id]); // edited after the replacement
    $elements->saveElement($edited) or throw new RuntimeException(json_encode($edited->getErrors()));
    $flush();
    $rb = ReplaceService::rollback(internetztube\elementRelations\records\ReplacementRecord::findOne($op['operationId']));
    check($rb['restored'] === 0 && count($rb['skipped']) === 1 && $ids($both) === [$target->id, $b->id], 'a field edited since is not overwritten; the rollback reports it');
    $elements->deleteElement($both, true);

    $plain = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => 'Page via replace (later edit)']);
    $plain->setFieldValue('relatedEntries', [$c->id]);
    $elements->saveElement($plain) or throw new RuntimeException(json_encode($plain->getErrors()));
    $flush();
    sleep(1); // Craft skips a revision when the last one is from the same second
    $op = ReplaceService::replace($c, $target, [$plain->id]);
    $flush();
    sleep(1);
    $later = Entry::find()->id($plain->id)->one();
    $later->setFieldValue('relatedEntries', [$target->id, $b->id]); // someone adds Page B afterwards
    $elements->saveElement($later) or throw new RuntimeException(json_encode($later->getErrors()));
    $flush();
    sleep(1);
    $rb = ReplaceService::rollback(internetztube\elementRelations\records\ReplacementRecord::findOne($op['operationId']));
    $flush();
    check($rb['restored'] === 1 && $ids($plain) === [$c->id, $b->id], 'rollback swaps the reference back and keeps later edits (Page B stays)');

    // entries get a revision with a note for both steps
    $revisions = (new craft\db\Query())->select(['notes', 'creatorId'])->from('{{%revisions}}')->where(['canonicalId' => $plain->id])->orderBy(['num' => SORT_ASC])->all();
    $notes = implode(' | ', array_column($revisions, 'notes'));
    check(str_contains($notes, 'replaced “Page via replace (from)” with “Page via replace (target)”') && str_contains($notes, 'rolled back'), "entries get revisions noting the replacement and the rollback ($notes)");
    $admin = craft\elements\User::find()->admin()->status(null)->orderBy(['elements.id' => SORT_ASC])->one(); // console: the oldest admin
    check((int)end($revisions)['creatorId'] === (int)$admin->id, 'the revision is attributed to the user who rolled back');
    $elements->deleteElement($plain, true);

    // the control panel queues replacements and rollbacks (queue: true / queueRollback())
    $queued = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => 'Page via replace (queued)']);
    $queued->setFieldValue('relatedEntries', [$c->id]);
    $elements->saveElement($queued) or throw new RuntimeException(json_encode($queued->getErrors()));
    $flush();
    $op = ReplaceService::replace($c, $target, [$queued->id], queue: true);
    check($op['queued'] === true && internetztube\elementRelations\records\ReplacementRecord::findOne($op['operationId'])->status === 'queued' && in_array('Page via replace (queued)', relatingTitles($c), true), 'queue: true queues the replacement, nothing changed yet');
    $flush();
    $record = internetztube\elementRelations\records\ReplacementRecord::findOne($op['operationId']);
    check($record->status === 'applied' && in_array('Page via replace (queued)', relatingTitles($target), true), 'the queue job applies it and logs the result (' . $record->result . ')');
    check(ReplaceService::replace($c, $target, [$queued->id], queue: true)['queued'] === false, 'nothing left to replace: not queued at all (the endpoint answers right away)');
    ReplaceService::queueRollback($record);
    check(internetztube\elementRelations\records\ReplacementRecord::findOne($record->id)->status === 'rollbackQueued' && in_array('Page via replace (queued)', relatingTitles($target), true), 'queueRollback() marks it "rolling back", nothing changed yet');
    $flush();
    check(internetztube\elementRelations\records\ReplacementRecord::findOne($record->id)->status === 'rolledBack' && in_array('Page via replace (queued)', relatingTitles($c), true), 'the rollback job rolls it back');
    $elements->deleteElement($queued, true);

    // batches: one owner group per batch, so every batch hands over to the next (elements with thousands of usages)
    $batchSources = [];
    foreach (['a', 'b', 'c'] as $n) {
        $batchSource = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => "Page via replace (batch $n)"]);
        $batchSource->setFieldValue('relatedEntries', [$c->id]);
        $elements->saveElement($batchSource) or throw new RuntimeException(json_encode($batchSource->getErrors()));
        $batchSources[] = $batchSource;
    }
    $flush();
    $batchIds = array_map(fn(Entry $e) => (int)$e->id, $batchSources);
    $siteRows = (int)Entry::find()->id($batchIds)->site('*')->status(null)->count(); // one owner group per entry and site
    $op = ReplaceService::replace($c, $target, $batchIds, queue: true);
    $record = internetztube\elementRelations\records\ReplacementRecord::findOne($op['operationId']);
    check(count($record->getPlan()) === $siteRows, 'the plan has one owner group per source and site (' . count($record->getPlan()) . ')');
    (new craft\db\Query())->createCommand()->delete(craft\db\Table::QUEUE)->execute(); // run it with batches of one instead
    Craft::$app->getQueue()->push(new internetztube\elementRelations\jobs\ReplaceElementJob(['operationId' => (int)$record->id, 'batchSize' => 1]));
    $flush();
    $record = internetztube\elementRelations\records\ReplacementRecord::findOne($record->id);
    $titles = relatingTitles($target);
    check($record->status === 'applied' && $record->getResult()['replaced'] === count($batchIds) && count(array_intersect(['Page via replace (batch a)', 'Page via replace (batch b)', 'Page via replace (batch c)'], $titles)) === 3, 'in batches of one owner group, every source is replaced and the result adds up (' . $record->result . ')');
    check($record->plan === null && $record->state === null && count($record->getChanges()) === $siteRows, 'afterwards the plan and state are gone, the changes are complete');
    ReplaceService::queueRollback($record);
    (new craft\db\Query())->createCommand()->delete(craft\db\Table::QUEUE)->execute();
    Craft::$app->getQueue()->push(new internetztube\elementRelations\jobs\RollbackJob(['operationId' => (int)$record->id, 'batchSize' => 1]));
    $flush();
    $record = internetztube\elementRelations\records\ReplacementRecord::findOne($record->id);
    check($record->status === 'rolledBack' && count(array_intersect(['Page via replace (batch a)', 'Page via replace (batch b)', 'Page via replace (batch c)'], relatingTitles($c))) === 3, 'the rollback runs in batches too (' . $record->result . ')');
    // a stuck replacement (its job is gone, e.g. a worker died after the first batch) is detected and resumed
    $op = ReplaceService::replace($c, $target, $batchIds, queue: true);
    (new craft\db\Query())->createCommand()->delete(craft\db\Table::QUEUE)->execute(); // the job disappears
    $record = internetztube\elementRelations\records\ReplacementRecord::findOne($op['operationId']);
    $record->state = json_encode(['processed' => 1, 'total' => $siteRows]) ?: null; // as if one owner group was done
    $record->save(false);
    $record->updateAttributes(['dateUpdated' => craft\helpers\Db::prepareDateForDb(new DateTime('-20 minutes'))]);
    check(ReplaceService::isStuck($record), 'a queued replacement without progress for 20 minutes counts as stuck');
    ReplaceService::resume($record);
    $record = internetztube\elementRelations\records\ReplacementRecord::findOne($record->id);
    $resumedJobs = (new craft\db\Query())->from(craft\db\Table::QUEUE)->count();
    check(!ReplaceService::isStuck($record) && (int)$resumedJobs === 1, 'resume() queues a job again and the operation is not stuck anymore');
    $flush();
    $record = internetztube\elementRelations\records\ReplacementRecord::findOne($record->id);
    check($record->status === 'applied' && count(array_intersect(['Page via replace (batch b)', 'Page via replace (batch c)'], relatingTitles($target))) === 2, 'the resumed job continues after the groups already done and finishes (' . $record->result . ')');
    ReplaceService::rollback($record);
    $flush();

    // a batch that broke off after doing its work but before saving its progress: the resumed job meets elements it
    // already changed and must count them as done, not report them as needing a manual change
    $op = ReplaceService::replace($c, $target, $batchIds, queue: true);
    (new craft\db\Query())->createCommand()->delete(craft\db\Table::QUEUE)->execute();
    $record = internetztube\elementRelations\records\ReplacementRecord::findOne($op['operationId']);
    $groups = internetztube\elementRelations\services\ReplaceRun::groups($record);
    internetztube\elementRelations\services\ReplaceRun::resume($record, $c, $target)->processGroup($groups[0]); // no persist(): "crashed"
    ReplaceService::resume($record);
    $flush();
    $record = internetztube\elementRelations\records\ReplacementRecord::findOne($record->id);
    $result = $record->getResult();
    check($record->status === 'applied' && $result['replaced'] === count($batchIds) && empty($result['skipped']), 'after a broken-off batch, the resumed replacement counts what was already done and reports nothing to fix (' . $record->result . ')');
    ReplaceService::queueRollback($record);
    (new craft\db\Query())->createCommand()->delete(craft\db\Table::QUEUE)->execute();
    $record = internetztube\elementRelations\records\ReplacementRecord::findOne($record->id);
    internetztube\elementRelations\services\RollbackRun::start($record, ReplaceService::ROLLBACK_SWAP, null)->processGroup(internetztube\elementRelations\services\RollbackRun::groups($record)[0]);
    ReplaceService::resume($record);
    $flush();
    $record = internetztube\elementRelations\records\ReplacementRecord::findOne($record->id);
    $result = $record->getResult();
    check($record->status === 'rolledBack' && ($result['restored'] ?? 0) === count($batchIds) && empty($result['rollbackSkipped']) && count(array_intersect(['Page via replace (batch a)', 'Page via replace (batch b)', 'Page via replace (batch c)'], relatingTitles($c))) === 3, 'the same for a rollback: everything restored, nothing reported (' . $record->result . ')');

    foreach ($batchSources as $batchSource) {
        $elements->deleteElement($batchSource, true);
    }

    // developer events
    $probe = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => 'Page via replace (events)']);
    $probe->setFieldValue('relatedEntries', [$c->id]);
    $elements->saveElement($probe) or throw new RuntimeException(json_encode($probe->getErrors()));
    $flush();
    $relatedField = Craft::$app->getFields()->getFieldByHandle('relatedEntries');
    $removeRelationExtractors = function (craft\events\RegisterComponentTypesEvent $e) {
        $e->types = array_values(array_diff($e->types, [
            internetztube\elementRelations\services\extractors\FieldExtractorBaseRelationService::class,
            internetztube\elementRelations\services\extractors\FieldExtractorRelationalFieldService::class,
        ]));
    };
    yii\base\Event::on(internetztube\elementRelations\services\ExtractorService::class, internetztube\elementRelations\services\ExtractorService::EVENT_REGISTER_FIELD_EXTRACTORS, $removeRelationExtractors);
    internetztube\elementRelations\services\ExtractorService::reset();
    check(internetztube\elementRelations\services\ExtractorService::targetIds($probe, $relatedField) === [], 'EVENT_REGISTER_FIELD_EXTRACTORS can remove extractors');
    yii\base\Event::off(internetztube\elementRelations\services\ExtractorService::class, internetztube\elementRelations\services\ExtractorService::EVENT_REGISTER_FIELD_EXTRACTORS, $removeRelationExtractors);
    internetztube\elementRelations\services\ExtractorService::reset();
    check(internetztube\elementRelations\services\ExtractorService::targetIds($probe, $relatedField) === [$c->id], '…and without the handler they are back');

    $cancel = fn(internetztube\elementRelations\events\ReplaceEvent $e) => $e->isValid = false;
    yii\base\Event::on(ReplaceService::class, ReplaceService::EVENT_BEFORE_REPLACE, $cancel);
    $r = ReplaceService::replace($c, $target, [$probe->id]);
    yii\base\Event::off(ReplaceService::class, ReplaceService::EVENT_BEFORE_REPLACE, $cancel);
    $flush();
    check($r['replaced'] === 0 && $r['operationId'] === null && in_array('Page via replace (events)', relatingTitles($c), true), 'EVENT_BEFORE_REPLACE can stop a replacement');
    $seen = [];
    $after = function (internetztube\elementRelations\events\ReplaceEvent $e) use (&$seen) { $seen[] = $e->name . ':' . ($e->result['replaced'] ?? $e->result['restored'] ?? '?'); };
    yii\base\Event::on(ReplaceService::class, ReplaceService::EVENT_AFTER_REPLACE, $after);
    yii\base\Event::on(ReplaceService::class, ReplaceService::EVENT_AFTER_ROLLBACK, $after);
    $r = ReplaceService::replace($c, $target, [$probe->id]);
    $flush();
    ReplaceService::rollback(internetztube\elementRelations\records\ReplacementRecord::findOne($r['operationId']));
    $flush();
    yii\base\Event::off(ReplaceService::class, ReplaceService::EVENT_AFTER_REPLACE, $after);
    yii\base\Event::off(ReplaceService::class, ReplaceService::EVENT_AFTER_ROLLBACK, $after);
    check($seen === ['afterReplace:1', 'afterRollback:1'], 'EVENT_AFTER_REPLACE and EVENT_AFTER_ROLLBACK fire with the result (' . implode(', ', $seen) . ')');

    // own log file
    Craft::getLogger()->flush(true);
    $logs = glob(Craft::getAlias('@storage/logs') . '/element-relations-*.log') ?: [];
    $logText = $logs ? file_get_contents(end($logs)) : '';
    check(str_contains($logText, 'Replaced #' . $c->id . ' by #' . $target->id) && str_contains($logText, 'Rolled back replacement #' . $r['operationId']), 'replacements and rollbacks are logged to storage/logs/element-relations-*.log');
    $elements->deleteElement($probe, true);

    // permissions: a non-admin needs them (Craft Solo has a single user, so lift the edition in memory for this check)
    $editionBeforePermissions = Craft::$app->edition;
    Craft::$app->edition = craft\enums\CmsEdition::Pro;
    foreach (craft\elements\User::find()->username('er-permissions')->status(null)->all() as $old) {
        $elements->deleteElement($old, true);
    }
    $editor = new craft\elements\User(['username' => 'er-permissions', 'email' => 'er-permissions@example.com']);
    $elements->saveElement($editor) or throw new RuntimeException(json_encode($editor->getErrors()));
    check(!ElementRelations::canReplace($editor) && !ElementRelations::canRollBack($editor), 'a non-admin without permissions can neither replace nor roll back');
    Craft::$app->getUserPermissions()->saveUserPermissions($editor->id, [ElementRelations::PERMISSION_REPLACE]);
    $editor = craft\elements\User::find()->id($editor->id)->status(null)->one();
    check(ElementRelations::canReplace($editor) && !ElementRelations::canRollBack($editor), 'the replace permission allows replacing, not rolling back');
    $groups = array_column(Craft::$app->getUserPermissions()->getAllPermissions(), 'permissions', 'heading');
    check(isset($groups['Element Relations'][ElementRelations::PERMISSION_REPLACE], $groups['Element Relations'][ElementRelations::PERMISSION_ROLLBACK]), 'both permissions are registered under “Element Relations”');
    $elements->deleteElement($editor, true);
    Craft::$app->edition = $editionBeforePermissions;

    $elements->deleteElement($source, true);
    $elements->deleteElement($other, true);
    if ($nested) {
        $elements->deleteElement($nested, true);
    }
    $elements->deleteElement($target, true);
    $elements->deleteElement($c, true);
    $elements->deleteElement($fresh, true);
    $plugins->switchEdition('element-relations', $editionBefore);
};
