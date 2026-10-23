<?php
/**
 * Pro revisions: owner revisions for nested changes, "revert to revisions" rollback, elements without revisions
 * (other element types, sections with versioning off) and the hints about them. Creates "RV ..." elements and a
 * section "RV No versioning"; everything is deleted again.
 */

use craft\elements\Category;
use craft\elements\Entry;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\records\ReplacementRecord;
use internetztube\elementRelations\services\ReplaceService;

return function (array $ctx): void {
    $app = Craft::$app;
    $elements = $app->getElements();
    $entriesService = $app->getEntries();
    $plugins = $app->getPlugins();
    $editionBefore = ElementRelations::getInstance()->edition;
    $plugins->switchEdition('element-relations', 'pro');
    $flush = function (): void {
        Craft::$app->trigger(yii\base\Application::EVENT_AFTER_REQUEST);
        Craft::$app->getQueue()->run();
    };
    $revisions = fn(int $id) => (new craft\db\Query())->select(['id', 'num', 'notes'])->from('{{%revisions}}')->where(['canonicalId' => $id])->orderBy(['num' => SORT_ASC])->all();
    $c = $ctx['target'];
    $pages = $entriesService->getSectionByHandle('pages');
    $pageType = $pages->getEntryTypes()[0];
    $matrix = $app->getFields()->getFieldByHandle('teasers');

    foreach (Entry::find()->title('RV *')->status(null)->all() as $old) {
        $elements->deleteElement($old, true);
    }
    if ($old = $entriesService->getSectionByHandle('rvNoVersioning')) {
        $entriesService->deleteSection($old);
    }
    $target = new Entry(['sectionId' => $pages->id, 'typeId' => $pageType->id, 'title' => 'RV target']);
    $elements->saveElement($target) or throw new RuntimeException(json_encode($target->getErrors()));
    $owner = new Entry(['sectionId' => $pages->id, 'typeId' => $pageType->id, 'title' => 'RV nested owner']);
    $owner->setFieldValue('teasers', ['new1' => ['type' => $matrix->getEntryTypes()[0]->handle, 'fields' => ['relatedEntries' => [$c->id]]]]);
    $elements->saveElement($owner) or throw new RuntimeException(json_encode($owner->getErrors()));
    $flush();
    $blockTargets = fn() => array_map('intval', Entry::find()->id($owner->id)->one()->getFieldValue('teasers')->one()->getFieldValue('relatedEntries')->ids());
    check($blockTargets() === [$c->id] && in_array('RV nested owner', relatingTitles($c), true), 'fixture: the owner\'s Matrix block relates to Page C');
    $revisionsBefore = count($revisions($owner->id));

    // --- a nested change gets an owner revision
    sleep(1); // Craft skips a revision when the previous one is from the same second
    $category = Category::find()->title('Test Category')->one();
    $result = ReplaceService::replace($c, $target, [$owner->id, $category->id]);
    $flush();
    check($result['replaced'] === 2 && $blockTargets() === [$target->id], 'replace rewrites the Matrix block and the category (' . json_encode($result) . ')');
    $ownerRevisions = $revisions($owner->id);
    check(count($ownerRevisions) === $revisionsBefore + 1 && str_contains((string)end($ownerRevisions)['notes'], 'replaced “Page C” with “RV target”'), 'the owner entry got a revision noting the replacement, although only its Matrix block changed');
    check($result['withoutRevision'] === 1, 'the category is counted as changed without revision');
    $operation = ReplacementRecord::findOne($result['operationId']);
    $owners = array_column($operation->getRevisions(), null, 'ownerId');
    check(!empty($owners[$owner->id]['revisionBeforeId']) && $owners[$owner->id]['support'] === ReplaceService::REVISIONS_YES, 'the operation remembers the owner\'s revision from before');
    check(($owners[$category->id]['support'] ?? null) === ReplaceService::REVISIONS_UNSUPPORTED && empty($owners[$category->id]['revisionBeforeId']), 'the category is recorded as not supporting revisions');
    check($operation->canRevertToRevisions(), 'the operation offers "revert to revisions"');

    // --- someone edits the owner afterwards
    sleep(1);
    $edited = Entry::find()->id($owner->id)->one();
    $edited->title = 'RV nested owner (edited later)';
    $elements->saveElement($edited) or throw new RuntimeException(json_encode($edited->getErrors()));
    $flush();

    // --- revert to revisions: the entry goes back to its revision (later edit discarded), the category is swapped back
    sleep(1);
    $rb = ReplaceService::rollback($operation, ReplaceService::ROLLBACK_REVISIONS);
    $flush();
    $reverted = Entry::find()->id($owner->id)->one();
    check($rb['reverted'] === 1 && $blockTargets() === [$c->id], 'the owner is reverted to its revision: the Matrix block points to Page C again (' . json_encode($rb) . ')');
    check((string)$reverted === 'RV nested owner', 'reverting discards the later edit (title is back)');
    check(in_array('Test Category', relatingTitles($c), true) && !in_array('Test Category', relatingTitles($target), true), 'the category (no revisions) is swapped back instead');
    check(in_array('RV nested owner', relatingTitles($c), true) && !in_array('RV nested owner', relatingTitles($target), true), 'the relations cache follows the revert immediately');
    check(ReplacementRecord::findOne($operation->id)->status === ReplacementRecord::STATUS_ROLLED_BACK, 'the operation is marked rolled back');
    $operation->delete();

    // --- swap back on a nested change also gets an owner revision
    sleep(1);
    $again = ReplaceService::replace($c, $target, [$owner->id]);
    $flush();
    sleep(1);
    $countBefore = count($revisions($owner->id));
    ReplaceService::rollback(ReplacementRecord::findOne($again['operationId']));
    $flush();
    $last = $revisions($owner->id);
    check(count($last) === $countBefore + 1 && str_contains((string)end($last)['notes'], 'rolled back'), 'swapping back inside a Matrix block also makes an owner revision');
    ReplacementRecord::findOne($again['operationId'])->delete();

    // --- same-second saves: Craft skips such revisions, the plugin must not rely on them (no sleep on purpose)
    $fast = new Entry(['sectionId' => $pages->id, 'typeId' => $pageType->id, 'title' => 'RV fast source']);
    $fast->setFieldValue('relatedEntries', [$c->id]);
    $elements->saveElement($fast) or throw new RuntimeException(json_encode($fast->getErrors()));
    $flush();
    $fastTargets = fn() => array_map('intval', Entry::find()->id($fast->id)->one()->getFieldValue('relatedEntries')->ids());
    $first = ReplaceService::replace($c, $target, [$fast->id]);
    ReplaceService::rollback(ReplacementRecord::findOne($first['operationId'])); // same second
    check($fastTargets() === [$c->id], 'fast: replace + immediate rollback restore Page C');
    $second = ReplaceService::replace($c, $target, [$fast->id]); // same second again
    check($fastTargets() === [$target->id], 'fast: second replacement applied');
    $rb = ReplaceService::rollback(ReplacementRecord::findOne($second['operationId']), ReplaceService::ROLLBACK_REVISIONS);
    $flush();
    check($rb['reverted'] === 1 && $fastTargets() === [$c->id], 'fast: revert to revisions returns to the state right before the second replacement (Page C), although every save happened within one second (' . json_encode($rb) . ')');
    $notes = array_column($revisions($fast->id), 'notes');
    check(count(array_filter($notes, fn($n) => str_contains((string)$n, 'replaced “Page C” with “RV target”'))) === 2, 'fast: both replacements have their own revision despite the same second (' . implode(' | ', array_map('strval', $notes)) . ')');
    foreach ([$first['operationId'], $second['operationId']] as $opId) {
        ReplacementRecord::findOne($opId)?->delete();
    }
    $elements->deleteElement($fast, true);

    // --- sections without versioning, and the hints
    $noVersioning = new Section([
        'name' => 'RV No versioning', 'handle' => 'rvNoVersioning', 'type' => Section::TYPE_CHANNEL, 'enableVersioning' => false,
        'siteSettings' => array_map(fn($site) => new Section_SiteSettings(['siteId' => $site->id, 'hasUrls' => false]), $app->getSites()->getAllSites()),
    ]);
    $noVersioning->setEntryTypes([$pageType]);
    $entriesService->saveSection($noVersioning) or throw new RuntimeException(json_encode($noVersioning->getErrors()));
    $plain = new Entry(['sectionId' => $noVersioning->id, 'typeId' => $pageType->id, 'title' => 'RV unversioned source']);
    $plain->setFieldValue('relatedEntries', [$c->id]);
    $elements->saveElement($plain) or throw new RuntimeException(json_encode($plain->getErrors()));
    $flush();
    check(ReplaceService::revisionSupport($plain) === ReplaceService::REVISIONS_VERSIONING_OFF, 'an entry in a section without versioning: versioning off');
    check(ReplaceService::revisionSupport($category) === ReplaceService::REVISIONS_UNSUPPORTED && ReplaceService::revisionSupport($owner) === ReplaceService::REVISIONS_YES, 'categories: unsupported; versioned entries: yes');
    $coverage = ReplaceService::revisionCoverage($c->id);
    check($coverage['versioningOff'] >= 1 && $coverage['unsupported'] >= 3 && $coverage['total'] > $coverage['versioningOff'] + $coverage['unsupported'], 'revisionCoverage counts usages without revisions by reason (' . json_encode($coverage) . ')');
    $r = ReplaceService::replace($c, $target, [$plain->id]);
    $flush();
    check($r['withoutRevision'] === 1 && $revisions($plain->id) === [], 'replacing in it works and is reported as without revision; no revision exists');
    check(str_contains(internetztube\elementRelations\controllers\ReplaceController::resultMessage($r, 'Page C', 'RV target'), 'no revision'), 'the success message says so');
    $op = ReplacementRecord::findOne($r['operationId']);
    check(!$op->canRevertToRevisions(), 'such an operation offers no "revert to revisions", only Roll back');
    ReplaceService::rollback($op);
    $flush();
    check(in_array('RV unversioned source', relatingTitles($c), true), 'Roll back works without revisions');
    $op->delete();

    $hint = withWebRequest(fn() => internetztube\elementRelations\fields\ElementRelationsField::revisionHintFor($c));
    check(str_contains($hint, 'keep no revision') || str_contains($hint, 'keeps no revision'), "the wizard's review mentions the usages without revisions ($hint)");

    // --- cleanup
    foreach ([$plain, $owner, $target] as $element) {
        $elements->deleteElement($element, true);
    }
    $entriesService->deleteSection($noVersioning);
    $plugins->switchEdition('element-relations', $editionBefore);
    $flush();
};
