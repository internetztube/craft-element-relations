<?php
/**
 * Drafts and revisions, end to end through the real save path (save event -> queued refresh -> cache).
 * Creates its own elements (titles "DX ...") and deletes them again.
 */

use craft\elements\Entry;
use internetztube\elementRelations\models\RelationsModel;

return function (array $ctx): void {
    $elements = Craft::$app->getElements();
    $drafts = Craft::$app->getDrafts();
    $section = Craft::$app->getEntries()->getSectionByHandle('pages');
    $typeId = $section->getEntryTypes()[0]->id;
    $adminId = craft\elements\User::find()->username('admin')->one()->id;

    $flush = function (): void {
        Craft::$app->trigger(yii\base\Application::EVENT_AFTER_REQUEST);
        Craft::$app->getQueue()->run();
    };
    $relating = fn(Entry $target, ?bool $drafts = null) => array_map(
        fn($e) => $e->title . ($e->getIsDraft() ? ' [draft]' : ''),
        (new RelationsModel($target->id, $target->siteId))->getElements([], drafts: $drafts),
    );
    $newEntry = function (string $title, array $values = []) use ($section, $typeId, $elements): Entry {
        $e = new Entry(['sectionId' => $section->id, 'typeId' => $typeId, 'title' => $title]);
        $e->setFieldValues($values);
        $elements->saveElement($e) or throw new RuntimeException(json_encode($e->getErrors()));
        return $e;
    };
    $cleanup = function () use ($elements, $flush): void {
        foreach (Entry::find()->title('DX *')->status(null)->drafts(null)->provisionalDrafts(null)->all() as $old) {
            $elements->deleteElement($old, true);
        }
        $flush();
    };

    $cleanup();
    $target = $newEntry('DX target');
    $source = $newEntry('DX source');
    $flush();
    check($relating($target) === [], 'baseline: nothing relates');

    // a named draft adds the relation
    $draft = $drafts->createDraft($source, $adminId, 'My draft');
    $draft->setFieldValue('relatedEntries', [$target->id]);
    $elements->saveElement($draft);
    $flush();
    check($relating($target) === ['DX source [draft]'], 'a named draft that adds a relation shows up as a draft');
    check($relating($target, false) === [], 'canonical-only view (field default) hides it');
    check($relating($target, true) === ['DX source [draft]'], '"Drafts" view shows it');

    $drafts->applyDraft($draft);
    $flush();
    check($relating($target) === ['DX source'], 'after applying the draft the canonical element relates');
    $rows = (new craft\db\Query())->from('{{%elementrelations_cache}}')->where(['targetElementId' => $target->id])->count();
    check((int)$rows === 1, 'exactly one cache row after applying (draft row gone)');

    // a provisional draft (autosave) removing the relation does not change anything
    $source = Entry::find()->id($source->id)->one();
    $provisional = $drafts->createDraft($source, $adminId, null, null, [], true);
    $provisional->setFieldValue('relatedEntries', []);
    $elements->saveElement($provisional);
    $flush();
    check($relating($target) === ['DX source'], 'a provisional draft removing the relation does not affect the canonical');
    $elements->deleteElement($provisional, true);
    $flush();

    // an unpublished draft (never published entry)
    $new = new Entry(['sectionId' => $section->id, 'typeId' => $typeId, 'title' => 'DX unpublished']);
    $new->setFieldValue('relatedEntries', [$target->id]);
    $drafts->saveElementAsDraft($new, $adminId, null, null, false);
    $flush();
    check(in_array('DX unpublished [draft]', $relating($target, true), true), 'an unpublished draft relates, shown as a draft');
    check(!in_array('DX unpublished [draft]', $relating($target, false), true), 'and is hidden in the canonical-only view');

    // revisions never produce cache rows
    $source->title = 'DX source v2';
    $elements->saveElement($source);
    $flush();
    $revisionRows = (new craft\db\Query())
        ->from(['c' => '{{%elementrelations_cache}}'])
        ->innerJoin(['e' => craft\db\Table::ELEMENTS], '[[e.id]] = [[c.sourceElementId]]')
        ->where(['not', ['e.revisionId' => null]])->andWhere(['c.targetElementId' => $target->id])
        ->count();
    check((int)$revisionRows === 0 && $relating($target, false) === ['DX source v2'], 'revisions produce no rows; the canonical still relates');

    // viewing the target as a draft shows the canonical's relations
    $targetDraft = $drafts->createDraft($target, $adminId, 'Target draft');
    $value = $targetDraft->getFieldValue('relations');
    check($value->getElementId() === $target->id && $value->getCount([]) >= 1, 'a draft of the target shows the relations of its canonical element');
    $field = Craft::$app->getFields()->getFieldByHandle('relations');
    check(str_contains(withWebRequest(fn() => $field->getInputHtml($value, $targetDraft)), 'element-index'), 'field on a draft renders the index (bound to the canonical element)');
    check(str_contains(withWebRequest(fn() => $field->getInputHtml($new->getFieldValue('relations'), $new)), 'Drafts cannot be used'), 'field on an unpublished draft shows the draft hint');

    // Matrix block added in a draft, then applied
    if (Craft::$app->getFields()->getFieldByHandle('teasers')) {
        $owner = $newEntry('DX matrix owner');
        $flush();
        $md = $drafts->createDraft($owner, $adminId, 'Matrix draft');
        $md->setFieldValue('teasers', ['new1' => ['type' => 'teaser', 'enabled' => true, 'fields' => ['relatedEntries' => [$target->id]]]]);
        $elements->saveElement($md);
        $flush();
        check(in_array('DX matrix owner [draft]', $relating($target, true), true), 'a Matrix block added in a draft relates as the draft');
        $drafts->applyDraft($md);
        $flush();
        check(in_array('DX matrix owner', $relating($target, false), true), 'after applying, the canonical owner relates');
    }

    $cleanup();
    check($relating($target) === [] || !Entry::find()->id($target->id)->one(), 'cleanup removed the test elements');
};
