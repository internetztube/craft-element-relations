<?php

declare(strict_types=1);

/** Pro features (edition switched to Pro for this file): the unused query param and filter, "Replace everywhere". */

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Edition;
use CraftCms\Cms\Element\Element;
use CraftCms\Cms\Element\ElementIndexes;
use CraftCms\Cms\Element\ElementSources;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Matrix;
use CraftCms\Cms\Support\Facades\Conditions;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Plugins;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\UserPermissions;
use CraftCms\Cms\Support\Facades\Volumes;
use CraftCms\Cms\Support\Str;
use CraftCms\Cms\User\Data\PermissionGroup;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Elements\Actions\ReplaceRelations;
use Internetztube\ElementRelations\Elements\Actions\ReplaceUsages;
use Internetztube\ElementRelations\Elements\Conditions\UsedConditionRule;
use Internetztube\ElementRelations\Elements\Usage;
use Internetztube\ElementRelations\Events\FieldExtractorsResolving;
use Internetztube\ElementRelations\Events\GlobalUsageProvidersResolving;
use Internetztube\ElementRelations\Events\ReplacementRolledBack;
use Internetztube\ElementRelations\Events\ReplacingUsages;
use Internetztube\ElementRelations\Events\UsagesReplaced;
use Internetztube\ElementRelations\Extractors\FieldExtractorBaseRelationService;
use Internetztube\ElementRelations\Extractors\FieldExtractorRelationalFieldService;
use Internetztube\ElementRelations\Extractors\InterfaceGlobalUsage;
use Internetztube\ElementRelations\Fields\ElementRelationsField;
use Internetztube\ElementRelations\Jobs\ReplaceElementJob;
use Internetztube\ElementRelations\Jobs\RollbackJob;
use Internetztube\ElementRelations\Models\CacheRecord;
use Internetztube\ElementRelations\Models\GlobalUsage;
use Internetztube\ElementRelations\Models\RelationsModel;
use Internetztube\ElementRelations\Models\Replacement;
use Internetztube\ElementRelations\Services\ExtractorService;
use Internetztube\ElementRelations\Services\ReplaceRun;
use Internetztube\ElementRelations\Services\ReplaceService;
use Internetztube\ElementRelations\Services\RollbackRun;
use Internetztube\ElementRelations\Support\ActingUser;

if (!class_exists('SmokeLogoSetting')) {
    /** Plugin settings using an asset (4.x: SEOmatic's global image), as a provider of non-element usages adds them. */
    final class SmokeLogoSetting implements InterfaceGlobalUsage
    {
        public static ?int $assetId = null;

        public static function type(): string
        {
            return 'smoke-logo';
        }

        public static function refresh(): void
        {
            DB::table(CacheRecord::TABLE)->where('type', self::type())->delete();
            if (self::$assetId) {
                DB::table(CacheRecord::TABLE)->insert(['targetElementId' => self::$assetId, 'targetSiteId' => Sites::getPrimarySite()->id, 'type' => self::type(), 'globalKey' => 'logo', 'dateCreated' => now(), 'dateUpdated' => now(), 'uid' => Str::uuid()->toString()]);
            }
        }

        public static function usages(array $rows): array
        {
            return array_map(fn(array $row): GlobalUsage => new GlobalUsage(provider: 'smoke-logo', key: (string)$row['globalKey'], label: 'Smoke logo setting'), $rows);
        }
    }
}

return function (array $ctx): void {
    $editionBefore = ElementRelations::instance()?->edition ?? throw new RuntimeException('plugin');
    // Element::sources() memoizes per process; the edition changes within this file, so clear it before each read
    $sourceKeys = function (): array {
        (new ReflectionProperty(Element::class, 'sources'))->setValue(null, []);
        return array_column(array_filter(Asset::sources('index'), fn($s) => isset($s['key'])), 'key');
    };
    $actionTypes = fn(array $actions): array => array_map(fn($a) => is_array($a) ? ($a['type'] ?? '') : (is_object($a) ? $a::class : $a), $actions);

    Plugins::switchEdition('element-relations', 'lite');
    $offersUsedRule = fn(): bool => in_array(UsedConditionRule::class, array_map(fn($rule) => $rule::class, Asset::createCondition()->getSelectableConditionRules()), true);
    check(!$offersUsedRule() && !in_array(ReplaceRelations::class, $actionTypes(Asset::actions('*')), true) && !Usage::actions('*'), 'Lite: no “Used anywhere” filter, no Replace actions');
    Plugins::switchEdition('element-relations', 'pro');
    check($offersUsedRule(), 'Pro: the asset filter offers “Used anywhere”');
    check(!in_array('elementRelations:unused', $sourceKeys(), true), 'no extra “Unused” source: the filter works on top of every source');
    check(in_array(ReplaceRelations::class, $actionTypes(Asset::actions('*')), true), 'Pro: asset index has the Replace action');

    // unused: the test asset is used (fields, the admin's photo), a fresh asset is not; one used only by plugin
    // settings is used
    $asset = $ctx['asset'];
    $fresh = createAsset('unused.png');
    $settingsOnly = createAsset('unused-settings.png');
    Event::listen(fn(GlobalUsageProvidersResolving $event) => $event->types[] = SmokeLogoSetting::class);
    RelationsModel::resetProviders();
    SmokeLogoSetting::$assetId = (int)$settingsOnly->id;
    RelationsModel::refreshGlobalUsages();
    $unusedIds = array_map('intval', Asset::find()->unused()->status(null)->ids());
    check(in_array((int)$fresh->id, $unusedIds, true) && !in_array((int)$asset->id, $unusedIds, true), 'unused(): fresh asset listed, used asset not');
    check(!in_array((int)$settingsOnly->id, array_map('intval', Asset::find()->unused()->ids()), true), 'plugin settings count as a usage');
    $usedIds = array_map('intval', Asset::find()->unused(false)->status(null)->ids());
    $allIds = array_map('intval', Asset::find()->status(null)->ids());
    sort($allIds);
    $both = [...$usedIds, ...$unusedIds];
    sort($both);
    check(in_array((int)$asset->id, $usedIds, true) && !in_array((int)$fresh->id, $usedIds, true), 'unused(false): the used asset listed, the fresh one not');
    check($both === $allIds && array_intersect($usedIds, $unusedIds) === [], 'unused(false) and unused() split every asset: together all ' . count($allIds) . ', none in both');
    $onCondition = Asset::createCondition();
    $onCondition->addConditionRule(Conditions::createConditionRule(['class' => UsedConditionRule::class, 'value' => true]));
    $viaOn = Asset::find()->status(null);
    $onCondition->modifyQuery($viaOn);
    check(array_map('intval', $viaOn->ids()) === $usedIds, 'the "Used anywhere" rule (on) lists only what is used (it filtered nothing before)');
    check(DB::table(CacheRecord::TABLE)->where('targetElementId', $settingsOnly->id)->whereNull('sourceElementId')->where('type', SmokeLogoSetting::type())->exists(), 'the plugin settings usage is a cache row without a source element');
    $condition = Asset::createCondition();
    $condition->addConditionRule(Conditions::createConditionRule(['class' => UsedConditionRule::class, 'value' => false]));
    $viaCondition = Asset::find()->status(null);
    $condition->modifyQuery($viaCondition);
    check(array_map('intval', $viaCondition->ids()) === $unusedIds, 'the "Used anywhere" condition rule (off) matches unused()');
    check($offersUsedRule(), 'the rule is offered in asset conditions (filter HUD, custom sources)');
    // sorting by the field, the way the asset index sorts by it (the field's sort option of the volume's source)
    $volume = Volumes::getVolumeByHandle('test') ?? throw new RuntimeException('volume');
    $sourceKey = "volume:$volume->uid";
    $fieldSort = app(ElementSources::class)->getSourceSortOptions(Asset::class, $sourceKey)->first(fn(array $option): bool => ($option['label'] ?? '') === 'Relations') ?? throw new RuntimeException('no sort option of the field');
    $sortedBy = function (string $direction) use ($sourceKey, $fieldSort): array {
        $query = Asset::find()->status(null);
        app(ElementIndexes::class)->applySort(Asset::class, $query, $sourceKey, [['field' => $fieldSort['attribute'], 'direction' => $direction]]);
        return array_map('intval', $query->ids());
    };
    $sorted = $sortedBy('desc');
    $usageCount = fn(int $id): int => (new RelationsModel($id, (int)Sites::getPrimarySite()->id))->getCountFast([]);
    check(array_search((int)$asset->id, $sorted, true) < array_search((int)$fresh->id, $sorted, true) && $usageCount($sorted[0]) >= $usageCount((int)$asset->id), 'sorting by the field puts the most used asset first and the unused one after it');
    check(array_search((int)$fresh->id, $sortedBy('asc'), true) < array_search((int)$asset->id, $sortedBy('asc'), true), '…and ascending the other way round');
    $a1 = Entry::find()->title('Page A1')->one() ?? throw new RuntimeException('A1'); // relates to Page B, nothing relates to it
    check(in_array((int)$a1->id, array_map('intval', Entry::find()->unused()->status(null)->ids()), true), 'unused() works for entries (Page A1 is a source, not a target)');

    // The sort and unused() check every listed element's usages. On a large, skewed cache table PostgreSQL expects
    // thousands of usages per element and, if the SQL lets it, hashes all of `elements` once per listed element: a
    // request that never finishes. With nested loops off it takes that plan whenever it can; the source element must
    // still be looked up per usage (by primary key; on the seed's tiny tables a filtered seq scan is cheaper), or in a
    // hashed subplan, which PostgreSQL builds once per query; never as the input of a join inside the per-element
    // subquery.
    if (DB::isPgsql()) {
        $elementsTable = DB::getTablePrefix() . Table::ELEMENTS;
        $sourceLookups = function (string $sql) use ($elementsTable): array {
            DB::statement('SET enable_nestloop = off');
            try {
                $plan = json_decode((string)(array_values((array)DB::selectOne('EXPLAIN (FORMAT JSON) ' . $sql))[0] ?? ''), true);
            } finally {
                DB::statement('RESET enable_nestloop');
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
        $sortQuery = Asset::find()->status(null)->limit(100);
        app(ElementIndexes::class)->applySort(Asset::class, $sortQuery, $sourceKey, [['field' => $fieldSort['attribute'], 'direction' => 'desc']]);
        $sortScans = $sourceLookups($sortQuery->toRawSql());
        $ok = $byPrimaryKey($sortScans);
        check($ok, 'PostgreSQL: sorting by the field looks up each usage\'s source on its own, never scans all elements per listed element' . ($ok ? '' : ': ' . json_encode($sortScans)));
        $unusedScans = $sourceLookups(Asset::find()->unused()->status(null)->toRawSql());
        $ok = $byPrimaryKey($unusedScans);
        check($ok, 'PostgreSQL: unused() looks up each usage\'s source on its own, never scans all elements per listed element' . ($ok ? '' : ': ' . json_encode($unusedScans)));
    }

    // replace: own "from" entry (Page C is used by every integration seed; replacing it would rewrite them all)
    $b = Entry::find()->title('Page B')->one() ?? throw new RuntimeException('B');
    $section = Sections::getSectionByHandle('pages') ?? throw new RuntimeException('pages');
    foreach (Entry::find()->title('Page via replace*')->status(null)->all() as $old) {
        Elements::deleteElement($old, true);
    }
    $page = function (string $title, array $relatedEntries = []) use ($section): Entry {
        $entry = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => $title]);
        if ($relatedEntries) {
            $entry->setFieldValue('relatedEntries', $relatedEntries);
        }
        Elements::saveElement($entry) or fail($entry);
        return $entry;
    };
    $c = $page('Page via replace (from)');
    $target = $page('Page via replace (target)');
    $source = $page('Page via replace', [$b->id, $c->id]);
    $other = $page('Page via replace (other)', [$c->id]);
    flushQueue();
    check(in_array('Page via replace', relatingTitles($c), true) && in_array('Page via replace (other)', relatingTitles($c), true), 'before: both sources relate to the "from" entry');

    // wizard: only the ticked usages; the ticked ids are owners (what the field lists), nested sources match through them
    $matrixField = Fields::getFieldByHandle('teasers');
    check($matrixField instanceof Matrix, 'the Matrix field of the seed is there');
    assert($matrixField instanceof Matrix);
    $nested = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => 'Page via replace (nested)']);
    $nested->setFieldValue('teasers', ['new1' => ['type' => $matrixField->getEntryTypes()[0]->handle, 'fields' => ['relatedEntries' => [$c->id]]]]);
    Elements::saveElement($nested) or fail($nested);
    flushQueue();
    check(in_array('Page via replace (nested)', relatingTitles($c), true), 'nested: a Matrix block of the nested entry relates to the "from" entry');
    $viaOwner = ReplaceService::replace($c, $target, [(int)$nested->id]);
    flushQueue();
    check($viaOwner['replaced'] === 1 && in_array('Page via replace (nested)', relatingTitles($target), true) && !in_array('Page via replace (nested)', relatingTitles($c), true), 'nested: selecting the owner replaces inside its Matrix block');
    $subset = ReplaceService::replace($c, $target, [(int)$other->id]);
    check(in_array('Page via replace (other)', relatingTitles($target), true), 'the cache is updated synchronously (before the queue runs)');
    flushQueue();
    check($subset['replaced'] === 1 && in_array('Page via replace', relatingTitles($c), true) && in_array('Page via replace (other)', relatingTitles($target), true), 'replace() limited to selected sources touches only those');
    $field = Fields::getFieldByHandle('relations');
    assert($field instanceof ElementRelationsField);
    $input = withWebRequest(fn(): string => $field->inputHtmlFor($c->getFieldValue('relations'), $c));
    check(str_contains($input, 'data-element-relations-from="' . $c->id . '"'), 'Pro: the field tells the index which element its "Replace…" action replaces');
    check(in_array(ReplaceUsages::class, Usage::actions('*'), true), 'Pro: the usage list offers "Replace…" for the ticked usages (an element action, so Craft shows checkboxes)');

    $result = ReplaceService::replace($c, $target);
    check($result['replaced'] === 1 && !$result['skipped'], 'replace() everywhere: exactly the remaining source was rewritten, nothing skipped (' . json_encode($result) . ')');
    flushQueue();
    $reloaded = Entry::find()->id($source->id)->one() ?? throw new RuntimeException('source');
    $ids = array_map('intval', $reloaded->getFieldValue('relatedEntries')->ids());
    check($ids === [(int)$b->id, (int)$target->id], 'entries field: C replaced by the target, order kept, B untouched (' . implode(',', $ids) . ')');
    check(!in_array('Page via replace', relatingTitles($c), true) && in_array('Page via replace', relatingTitles($target), true), 'cache follows: source now relates to the target, not to the "from" entry');
    SmokeLogoSetting::$assetId = (int)$asset->id;
    RelationsModel::refreshGlobalUsages();
    $skipped = ReplaceService::replace($asset, $fresh)['skipped']; // swapped back right below
    check(in_array('Smoke logo setting', $skipped, true), 'replacing the asset reports the plugin settings that use it as a manual change (' . implode(' | ', $skipped) . ')');
    flushQueue();
    ReplaceService::replace($fresh, $asset); // undo the asset swap
    flushQueue();
    SmokeLogoSetting::$assetId = null;
    RelationsModel::refreshGlobalUsages();
    Event::forget(GlobalUsageProvidersResolving::class);
    RelationsModel::resetProviders();

    // rollback edge cases on an entries field
    $both = $page('Page via replace (both)', [$c->id, $target->id]);
    flushQueue();
    $idsOf = fn(Entry $e): array => array_map('intval', (Entry::find()->id($e->id)->one() ?? throw new RuntimeException('gone'))->getFieldValue('relatedEntries')->ids());
    $operation = fn(array $result): Replacement => Replacement::query()->find($result['operationId']) ?? throw new RuntimeException('no operation');
    $op = ReplaceService::replace($c, $target, [(int)$both->id]);
    flushQueue();
    check($idsOf($both) === [(int)$target->id], 'replacing in a field that already held the target leaves it once');
    $rb = ReplaceService::rollback($operation($op));
    flushQueue();
    check($rb['restored'] === 1 && $idsOf($both) === [(int)$c->id, (int)$target->id], 'rollback restores the exact previous value (both, in order)');
    $op = ReplaceService::replace($c, $target, [(int)$both->id]);
    flushQueue();
    $edited = Entry::find()->id($both->id)->one() ?? throw new RuntimeException('both');
    $edited->setFieldValue('relatedEntries', [$target->id, $b->id]); // edited after the replacement
    Elements::saveElement($edited) or fail($edited);
    flushQueue();
    $rb = ReplaceService::rollback($operation($op));
    check($rb['restored'] === 0 && count($rb['skipped']) === 1 && $idsOf($both) === [(int)$target->id, (int)$b->id], 'a field edited since is not overwritten; the rollback reports it');
    Elements::deleteElement($both, true);

    $plain = $page('Page via replace (later edit)', [$c->id]);
    flushQueue();
    sleep(1); // Craft skips a revision when the last one is from the same second
    $op = ReplaceService::replace($c, $target, [(int)$plain->id]);
    flushQueue();
    sleep(1);
    $later = Entry::find()->id($plain->id)->one() ?? throw new RuntimeException('plain');
    $later->setFieldValue('relatedEntries', [$target->id, $b->id]); // someone adds Page B afterwards
    Elements::saveElement($later) or fail($later);
    flushQueue();
    sleep(1);
    // as the admin (4.x's console process had the admin as its user once a test had rendered the field)
    $rb = ActingUser::run(admin(), fn(): array => ReplaceService::rollback($operation($op)));
    flushQueue();
    check($rb['restored'] === 1 && $idsOf($plain) === [(int)$c->id, (int)$b->id], 'rollback swaps the reference back and keeps later edits (Page B stays)');

    // entries get a revision with a note for both steps
    $revisions = DB::table(Table::REVISIONS)->where('canonicalId', $plain->id)->orderBy('num')->get(['notes', 'creatorId'])->all();
    $notes = implode(' | ', array_map(fn(object $revision): string => (string)$revision->notes, $revisions));
    check(str_contains($notes, 'replaced “Page via replace (from)” with “Page via replace (target)”') && str_contains($notes, 'rolled back'), "entries get revisions noting the replacement and the rollback ($notes)");
    $lastRevision = end($revisions);
    check($lastRevision !== false && (int)$lastRevision->creatorId === (int)admin()->id, 'the revision is attributed to the user who rolled back');
    Elements::deleteElement($plain, true);

    // the control panel queues replacements and rollbacks (queue: true / queueRollback())
    $queued = $page('Page via replace (queued)', [$c->id]);
    flushQueue();
    $op = ReplaceService::replace($c, $target, [(int)$queued->id], queue: true);
    check($op['queued'] === true && $operation($op)->status === Replacement::STATUS_QUEUED && in_array('Page via replace (queued)', relatingTitles($c), true), 'queue: true queues the replacement, nothing changed yet');
    flushQueue();
    $record = $operation($op);
    check($record->status === Replacement::STATUS_APPLIED && in_array('Page via replace (queued)', relatingTitles($target), true), 'the queue job applies it and logs the result (' . $record->result . ')');
    check(ReplaceService::replace($c, $target, [(int)$queued->id], queue: true)['queued'] === false, 'nothing left to replace: not queued at all (the endpoint answers right away)');
    ReplaceService::queueRollback($record);
    check($operation($op)->status === Replacement::STATUS_ROLLBACK_QUEUED && in_array('Page via replace (queued)', relatingTitles($target), true), 'queueRollback() marks it "rolling back", nothing changed yet');
    flushQueue();
    check($operation($op)->status === Replacement::STATUS_ROLLED_BACK && in_array('Page via replace (queued)', relatingTitles($c), true), 'the rollback job rolls it back');
    Elements::deleteElement($queued, true);

    // batches: one owner group per batch, so every batch hands over to the next (elements with thousands of usages)
    $batchSources = [];
    foreach (['a', 'b', 'c'] as $n) {
        $batchSources[] = $page("Page via replace (batch $n)", [$c->id]);
    }
    flushQueue();
    $batchIds = array_map(fn(Entry $e): int => (int)$e->id, $batchSources);
    $batchTitles = ['Page via replace (batch a)', 'Page via replace (batch b)', 'Page via replace (batch c)'];
    $siteRows = Entry::find()->id($batchIds)->site('*')->status(null)->count(); // one owner group per entry and site
    $op = ReplaceService::replace($c, $target, $batchIds, queue: true);
    $record = $operation($op);
    check(count($record->getPlan()) === $siteRows, 'the plan has one owner group per source and site (' . count($record->getPlan()) . ')');
    DB::table('jobs')->delete(); // run it with batches of one instead
    $job = new ReplaceElementJob((int)$record->id);
    $job->batchSize = 1;
    dispatch($job);
    flushQueue();
    $record = $operation($op);
    check($record->status === Replacement::STATUS_APPLIED && ($record->getResult()['replaced'] ?? null) === count($batchIds) && count(array_intersect($batchTitles, relatingTitles($target))) === 3, 'in batches of one owner group, every source is replaced and the result adds up (' . $record->result . ')');
    check($record->plan === null && $record->state === null && count($record->changeList()) === $siteRows, 'afterwards the plan and state are gone, the changes are complete');
    ReplaceService::queueRollback($record);
    DB::table('jobs')->delete();
    $job = new RollbackJob((int)$record->id);
    $job->batchSize = 1;
    dispatch($job);
    flushQueue();
    $record = $operation($op);
    check($record->status === Replacement::STATUS_ROLLED_BACK && count(array_intersect($batchTitles, relatingTitles($c))) === 3, 'the rollback runs in batches too (' . $record->result . ')');
    // a stuck replacement (its job is gone, e.g. a worker died after the first batch) is detected and resumed
    $op = ReplaceService::replace($c, $target, $batchIds, queue: true);
    DB::table('jobs')->delete(); // the job disappears
    $record = $operation($op);
    $record->state = json_encode(['processed' => 1, 'total' => $siteRows]) ?: null; // as if one owner group was done
    $record->save();
    DB::table(Replacement::TABLE)->where('id', $record->id)->update(['dateUpdated' => now()->subMinutes(20)]);
    $record = $operation($op);
    check(ReplaceService::isStuck($record), 'a queued replacement without progress for 20 minutes counts as stuck');
    ReplaceService::resume($record);
    $record = $operation($op);
    check(!ReplaceService::isStuck($record) && DB::table('jobs')->count() === 1, 'resume() queues a job again and the operation is not stuck anymore');
    flushQueue();
    $record = $operation($op);
    check($record->status === Replacement::STATUS_APPLIED && count(array_intersect(['Page via replace (batch b)', 'Page via replace (batch c)'], relatingTitles($target))) === 2, 'the resumed job continues after the groups already done and finishes (' . $record->result . ')');
    ReplaceService::rollback($record);
    flushQueue();

    // a batch that broke off after doing its work but before saving its progress: the resumed job meets elements it
    // already changed and must count them as done, not report them as needing a manual change
    $op = ReplaceService::replace($c, $target, $batchIds, queue: true);
    DB::table('jobs')->delete();
    $record = $operation($op);
    $groups = ReplaceRun::groups($record);
    ReplaceRun::resume($record, $c, $target)->processGroup($groups[0]); // no persist(): "crashed"
    ReplaceService::resume($record);
    flushQueue();
    $record = $operation($op);
    $result = $record->getResult();
    check($record->status === Replacement::STATUS_APPLIED && ($result['replaced'] ?? null) === count($batchIds) && empty($result['skipped']), 'after a broken-off batch, the resumed replacement counts what was already done and reports nothing to fix (' . $record->result . ')');
    ReplaceService::queueRollback($record);
    DB::table('jobs')->delete();
    $record = $operation($op);
    RollbackRun::start($record, ReplaceService::ROLLBACK_SWAP, null)->processGroup(RollbackRun::groups($record)[0]);
    ReplaceService::resume($record);
    flushQueue();
    $record = $operation($op);
    $result = $record->getResult();
    check($record->status === Replacement::STATUS_ROLLED_BACK && ($result['restored'] ?? 0) === count($batchIds) && empty($result['rollbackSkipped']) && count(array_intersect($batchTitles, relatingTitles($c))) === 3, 'the same for a rollback: everything restored, nothing reported (' . $record->result . ')');
    foreach ($batchSources as $batchSource) {
        Elements::deleteElement($batchSource, true);
    }

    // developer events
    $probe = $page('Page via replace (events)', [$c->id]);
    flushQueue();
    $relatedField = Fields::getFieldByHandle('relatedEntries');
    assert($relatedField instanceof CraftCms\Cms\Field\Field);
    Event::listen(fn(FieldExtractorsResolving $e) => $e->types = array_values(array_diff($e->types, [FieldExtractorBaseRelationService::class, FieldExtractorRelationalFieldService::class])));
    ExtractorService::reset();
    check(ExtractorService::targetIds($probe, $relatedField) === [], 'FieldExtractorsResolving can remove extractors');
    Event::forget(FieldExtractorsResolving::class);
    ExtractorService::reset();
    check(ExtractorService::targetIds($probe, $relatedField) === [(int)$c->id], '…and without the handler they are back');

    Event::listen(fn(ReplacingUsages $e) => $e->isValid = false);
    $r = ReplaceService::replace($c, $target, [(int)$probe->id]);
    Event::forget(ReplacingUsages::class);
    flushQueue();
    check($r['replaced'] === 0 && $r['operationId'] === null && in_array('Page via replace (events)', relatingTitles($c), true), 'ReplacingUsages can stop a replacement');
    $seen = [];
    Event::listen(function (UsagesReplaced $e) use (&$seen): void {
        $seen[] = 'replaced:' . $e->result['replaced'];
    });
    Event::listen(function (ReplacementRolledBack $e) use (&$seen): void {
        $seen[] = 'rolledBack:' . ($e->result['restored'] ?? '?');
    });
    $r = ReplaceService::replace($c, $target, [(int)$probe->id]);
    flushQueue();
    ReplaceService::rollback($operation($r));
    flushQueue();
    Event::forget(UsagesReplaced::class);
    Event::forget(ReplacementRolledBack::class);
    check($seen === ['replaced:1', 'rolledBack:1'], 'UsagesReplaced and ReplacementRolledBack fire with the result (' . implode(', ', $seen) . ')');

    // own log file
    $logs = glob(storage_path('logs') . '/element-relations-*.log') ?: [];
    sort($logs);
    $logText = $logs ? (string)file_get_contents((string)end($logs)) : '';
    check(str_contains($logText, 'Replaced #' . $c->id . ' by #' . $target->id) && str_contains($logText, 'Rolled back replacement #' . $r['operationId']), 'replacements and rollbacks are logged to storage/logs/element-relations-*.log');
    Elements::deleteElement($probe, true);

    // permissions: a non-admin needs them (Craft Solo has a single user, so lift the edition in memory for this check)
    $craftEditionBefore = Context::getHidden(Edition::class);
    Context::addHidden(Edition::class, Edition::Pro);
    foreach (User::find()->username('er-permissions')->status(null)->all() as $old) {
        Elements::deleteElement($old, true);
    }
    $editor = new User(['username' => 'er-permissions', 'email' => 'er-permissions@example.com']);
    Elements::saveElement($editor) or fail($editor);
    check(!ElementRelations::canReplace($editor) && !ElementRelations::canRollBack($editor), 'a non-admin without permissions can neither replace nor roll back');
    UserPermissions::saveUserPermissions((int)$editor->id, [ElementRelations::PERMISSION_REPLACE]);
    $editor = User::find()->id($editor->id)->status(null)->one() ?? throw new RuntimeException('editor');
    check(ElementRelations::canReplace($editor) && !ElementRelations::canRollBack($editor), 'the replace permission allows replacing, not rolling back');
    $group = UserPermissions::getAllPermissions()->first(fn(PermissionGroup $group): bool => $group->heading === 'Element Relations');
    check($group instanceof PermissionGroup && !array_diff([ElementRelations::PERMISSION_REPLACE, ElementRelations::PERMISSION_ROLLBACK], $group->keys), 'both permissions are registered under “Element Relations”');
    Elements::deleteElement($editor, true);
    $craftEditionBefore === null ? Context::forgetHidden(Edition::class) : Context::addHidden(Edition::class, $craftEditionBefore);

    foreach ([$source, $other, $nested, $target, $c, $fresh, $settingsOnly] as $element) {
        Elements::deleteElement($element, true);
    }
    flushQueue();
    Plugins::switchEdition('element-relations', $editionBefore);
};
