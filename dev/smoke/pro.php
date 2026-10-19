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
    check($sorted[0] === $asset->id && end($sorted) === $fresh->id, 'sorting by the field puts the used asset first and the unused one last');
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
    $flush();
    check(in_array('Page via replace', relatingTitles($c), true), 'before: source relates to the "from" entry');
    $rawBefore = $hasCk ? Entry::find()->id($source->id)->one()->getFieldValue('ckeditor')->getRawContent() : '';

    $result = ReplaceService::replace($c, $target);
    check($result['replaced'] === 1 && !$result['skipped'], 'replace(): exactly the source entry was rewritten, nothing skipped (' . json_encode($result) . ')');
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

    $elements->deleteElement($source, true);
    $elements->deleteElement($target, true);
    $elements->deleteElement($c, true);
    $elements->deleteElement($fresh, true);
    $plugins->switchEdition('element-relations', 'lite');
};
