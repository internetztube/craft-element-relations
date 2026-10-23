<?php

declare(strict_types=1);

/**
 * Drafts and revisions, end to end through the real save path (save event -> queued refresh -> cache).
 * Creates its own elements (titles "DX ...") and deletes them again.
 */

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Drafts;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\HtmlStack;
use CraftCms\Cms\Support\Facades\Sections;
use Illuminate\Support\Facades\DB;
use Internetztube\ElementRelations\Fields\ElementRelationsField;
use Internetztube\ElementRelations\Models\CacheRecord;
use Internetztube\ElementRelations\Models\RelationsModel;

return function (array $ctx): void {
    $section = Sections::getSectionByHandle('pages') ?? throw new RuntimeException('pages');
    $typeId = $section->getEntryTypes()[0]->id;
    $adminId = (int)admin()->id;

    $relating = fn(Entry $target, ?bool $drafts = null) => array_map(
        fn($e) => $e->title . ($e->getIsDraft() ? ' [draft]' : ''),
        (new RelationsModel((int)$target->id, (int)$target->siteId))->getElements([], drafts: $drafts),
    );
    $newEntry = function (string $title, array $values = []) use ($section, $typeId): Entry {
        $e = new Entry(['sectionId' => $section->id, 'typeId' => $typeId, 'title' => $title]);
        $e->setFieldValues($values);
        Elements::saveElement($e) or fail($e);
        return $e;
    };
    $cleanup = function (): void {
        foreach (Entry::find()->title('DX *')->status(null)->drafts(null)->provisionalDrafts(null)->all() as $old) {
            Elements::deleteElement($old, true);
        }
        flushQueue();
    };

    $cleanup();
    $target = $newEntry('DX target');
    $source = $newEntry('DX source');
    flushQueue();
    check($relating($target) === [], 'baseline: nothing relates');

    // a named draft adds the relation
    $draft = Drafts::createDraft($source, $adminId, 'My draft');
    $draft->setFieldValue('relatedEntries', [$target->id]);
    Elements::saveElement($draft);
    flushQueue();
    check($relating($target) === ['DX source [draft]'], 'a named draft that adds a relation shows up as a draft');
    check($relating($target, false) === [], 'canonical-only view (field default) hides it');
    check($relating($target, true) === ['DX source [draft]'], '"Drafts" view shows it');

    Drafts::applyDraft($draft);
    flushQueue();
    check($relating($target) === ['DX source'], 'after applying the draft the canonical element relates');
    check(DB::table(CacheRecord::TABLE)->where('targetElementId', $target->id)->count() === 1, 'exactly one cache row after applying (draft row gone)');

    // a provisional draft (autosave) removing the relation does not change anything
    $source = Entry::find()->id($source->id)->one() ?? throw new RuntimeException('DX source');
    $provisional = Drafts::createDraft($source, $adminId, null, null, [], true);
    $provisional->setFieldValue('relatedEntries', []);
    Elements::saveElement($provisional);
    flushQueue();
    check($relating($target) === ['DX source'], 'a provisional draft removing the relation does not affect the canonical');
    Elements::deleteElement($provisional, true);
    flushQueue();

    // an unpublished draft (never published entry)
    $new = new Entry(['sectionId' => $section->id, 'typeId' => $typeId, 'title' => 'DX unpublished']);
    $new->setFieldValue('relatedEntries', [$target->id]);
    Drafts::saveElementAsDraft($new, $adminId, null, null, false);
    flushQueue();
    check(in_array('DX unpublished [draft]', $relating($target, true), true), 'an unpublished draft relates, shown as a draft');
    check(!in_array('DX unpublished [draft]', $relating($target, false), true), 'and is hidden in the canonical-only view');

    // revisions never produce cache rows
    $source->title = 'DX source v2';
    Elements::saveElement($source);
    flushQueue();
    $revisionRows = DB::table(CacheRecord::TABLE . ' as c')
        ->join('elements as e', 'e.id', '=', 'c.sourceElementId')
        ->whereNotNull('e.revisionId')->where('c.targetElementId', $target->id)
        ->count();
    check($revisionRows === 0 && $relating($target, false) === ['DX source v2'], 'revisions produce no rows; the canonical still relates');

    // viewing the target as a draft shows the canonical's relations
    $targetDraft = Drafts::createDraft($target, $adminId, 'Target draft');
    $value = $targetDraft->getFieldValue('relations');
    check($value instanceof RelationsModel && $value->getElementId() === (int)$target->id && $value->getCount([]) >= 1, 'a draft of the target shows the relations of its canonical element');
    $field = Fields::getFieldByHandle('relations');
    assert($field instanceof ElementRelationsField);
    check(str_contains(withWebRequest(fn() => HtmlStack::capture(fn(): string => $field->inputHtmlFor($value, $targetDraft)))->html, 'element-index'), 'field on a draft renders the index (bound to the canonical element)');
    check(str_contains(withWebRequest(fn() => $field->inputHtmlFor($new->getFieldValue('relations'), $new)), 'Drafts cannot be used'), 'field on an unpublished draft shows the draft hint');

    // Matrix block added in a draft, then applied
    if (Fields::getFieldByHandle('teasers')) {
        $owner = $newEntry('DX matrix owner');
        flushQueue();
        $md = Drafts::createDraft($owner, $adminId, 'Matrix draft');
        $md->setFieldValue('teasers', ['new1' => ['type' => 'teaser', 'enabled' => true, 'fields' => ['relatedEntries' => [$target->id]]]]);
        Elements::saveElement($md);
        flushQueue();
        check(in_array('DX matrix owner [draft]', $relating($target, true), true), 'a Matrix block added in a draft relates as the draft');
        Drafts::applyDraft($md);
        flushQueue();
        check(in_array('DX matrix owner', $relating($target, false), true), 'after applying, the canonical owner relates');
    }

    $cleanup();
    check($relating($target) === [] || !Entry::find()->id($target->id)->one(), 'cleanup removed the test elements');
};
