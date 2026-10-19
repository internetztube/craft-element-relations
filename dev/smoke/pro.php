<?php
/** Pro features (edition switched to Pro for this file): unused query param/source and "Replace everywhere". */

use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\elements\actions\ReplaceRelations;
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
    check(!in_array('elementRelations:unused', $sourceKeys(), true) && !in_array(ReplaceRelations::class, Asset::actions('*'), true), 'Lite: no Unused source, no Replace action');
    $plugins->switchEdition('element-relations', 'pro');
    check(in_array('elementRelations:unused', $sourceKeys(), true), 'Pro: asset index has the Unused source');
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
    check(array_search($asset->id, $sorted, true) < array_search($fresh->id, $sorted, true) && $sorted[0] === $asset->id, 'sorting by the field puts the most used asset first and the unused one after it');
    $category = Category::find()->title('Test Category')->one(); // relates to Page C, nothing relates to it
    check($category && in_array($category->id, Category::find()->unused()->status(null)->ids(), true), 'unused() works for categories (Test Category is a source, not a target)');

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
    check(str_contains($input, 'data-element-relations-replace'), 'Pro: the field offers the Replace… wizard button');
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
    $settings = ElementRelations::getInstance()->getSettings();
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
    $admin = craft\elements\User::find()->admin()->one();
    check((int)end($revisions)['creatorId'] === (int)$admin->id, 'the revision is attributed to the user who rolled back');
    $elements->deleteElement($plain, true);

    // large operations run as a queue job (here: limit 0)
    $limitBefore = $settings->replaceSyncLimit;
    $settings->replaceSyncLimit = 0;
    $queued = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => 'Page via replace (queued)']);
    $queued->setFieldValue('relatedEntries', [$c->id]);
    $elements->saveElement($queued) or throw new RuntimeException(json_encode($queued->getErrors()));
    $flush();
    $op = ReplaceService::replace($c, $target, [$queued->id], allowQueue: true);
    check($op['queued'] === true && internetztube\elementRelations\records\ReplacementRecord::findOne($op['operationId'])->status === 'queued' && in_array('Page via replace (queued)', relatingTitles($c), true), 'above the sync limit the replacement is queued, nothing changed yet');
    $flush();
    $record = internetztube\elementRelations\records\ReplacementRecord::findOne($op['operationId']);
    check($record->status === 'applied' && in_array('Page via replace (queued)', relatingTitles($target), true), 'the queue job applies it and logs the result (' . $record->result . ')');
    ReplaceService::rollback($record);
    $flush();
    check(in_array('Page via replace (queued)', relatingTitles($c), true), 'and it rolls back like any other operation');
    $settings->replaceSyncLimit = $limitBefore;
    $elements->deleteElement($queued, true);

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
