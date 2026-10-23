<?php

declare(strict_types=1);

/**
 * Revisions: owner revisions for nested changes, "revert to revisions" rollback, elements without revisions (other
 * element types, sections with versioning off) and the hints about them. Creates "RV ..." elements, a section
 * "RV No versioning" and a volume "RV Assets" (an asset is the element type without revisions here; Craft 5's
 * categories are in Craft 6's Yii2 adapter only); everything is deleted again.
 */

use CraftCms\Cms\Asset\Data\Volume;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Asset\Folders;
use CraftCms\Cms\Asset\Validation\AssetRules;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Plugins;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\Volumes;
use Illuminate\Support\Facades\DB;
use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Fields\ElementRelationsField;
use Internetztube\ElementRelations\Http\Controllers\ReplaceController;
use Internetztube\ElementRelations\Models\Replacement;
use Internetztube\ElementRelations\Services\ReplaceService;

return function (array $ctx): void {
    $editionBefore = ElementRelations::instance()?->edition ?? 'pro';
    Plugins::switchEdition('element-relations', 'pro');
    $revisions = fn(int $id): array => DB::table('revisions')->select(['id', 'num', 'notes'])->where('canonicalId', $id)->orderBy('num')->get()->map(fn($row) => (array)$row)->all();
    $c = $ctx['target'];
    $pages = Sections::getSectionByHandle('pages') ?? throw new RuntimeException('pages');
    $pageType = $pages->getEntryTypes()[0];
    $matrix = Fields::getFieldByHandle('teasers') ?? throw new RuntimeException('teasers (seed/matrix.php)');
    $operation = fn(?int $id): Replacement => Replacement::query()->find($id) ?? throw new RuntimeException("operation $id");

    foreach (Entry::find()->title('RV *')->status(null)->all() as $old) {
        Elements::deleteElement($old, true);
    }
    if ($old = Sections::getSectionByHandle('rvNoVersioning')) {
        Sections::deleteSection($old);
    }
    if ($old = Volumes::getVolumeByHandle('rvAssets')) {
        Volumes::deleteVolume($old);
    }
    $target = new Entry(['sectionId' => $pages->id, 'typeId' => $pageType->id, 'title' => 'RV target']);
    Elements::saveElement($target) or fail($target);
    $owner = new Entry(['sectionId' => $pages->id, 'typeId' => $pageType->id, 'title' => 'RV nested owner']);
    $owner->setFieldValue('teasers', ['new1' => ['type' => $matrix->getEntryTypes()[0]->handle, 'fields' => ['relatedEntries' => [$c->id]]]]);
    Elements::saveElement($owner) or fail($owner);

    // an element without revisions relating to Page C: an asset in a volume whose layout has the Entries field
    $volume = new Volume(['name' => 'RV Assets', 'handle' => 'rvAssets', 'fsHandle' => 'public', 'subpath' => 'rv-assets', 'hasUrls' => true]);
    $volume->setFieldLayout(layoutFor(Asset::class, [Fields::getFieldByHandle('relatedEntries') ?? throw new RuntimeException('relatedEntries')]));
    Volumes::saveVolume($volume) or fail($volume);
    $volume = Volumes::getVolumeByHandle('rvAssets') ?? throw new RuntimeException('rvAssets');
    $tmp = tempnam(sys_get_temp_dir(), 'er') . '.png';
    file_put_contents($tmp, base64_decode(PNG_1X1));
    $unversioned = Elements::createElement([
        'type' => Asset::class,
        'volumeId' => $volume->id,
        'newFolderId' => app(Folders::class)->getRootFolderByVolumeId((int)$volume->id)->id,
        'tempFilePath' => $tmp,
        'newFilename' => 'rv-asset.png',
        'title' => 'RV asset',
        'avoidFilenameConflicts' => true,
    ]);
    assert($unversioned instanceof Asset);
    $unversioned->ruleset->useScenario(AssetRules::SCENARIO_CREATE);
    $unversioned->setFieldValue('relatedEntries', [$c->id]);
    Elements::saveElement($unversioned) or fail($unversioned);
    flushQueue();
    $blockTargets = fn(): array => array_map('intval', Entry::find()->id($owner->id)->one()?->getFieldValue('teasers')->one()?->getFieldValue('relatedEntries')->ids() ?? []);
    check($blockTargets() === [(int)$c->id] && in_array('RV nested owner', relatingTitles($c), true) && in_array('RV asset', relatingTitles($c), true), 'fixture: the owner\'s Matrix block and the asset relate to Page C');
    $revisionsBefore = count($revisions((int)$owner->id));

    // --- a nested change gets an owner revision
    sleep(1); // Craft skips a revision when the previous one is from the same second
    $result = ReplaceService::replace($c, $target, [(int)$owner->id, (int)$unversioned->id]);
    flushQueue();
    check($result['replaced'] === 2 && $blockTargets() === [(int)$target->id], 'replace rewrites the Matrix block and the asset (' . json_encode($result) . ')');
    $ownerRevisions = $revisions((int)$owner->id);
    check(count($ownerRevisions) === $revisionsBefore + 1 && str_contains((string)end($ownerRevisions)['notes'], 'replaced “Page C” with “RV target”'), 'the owner entry got a revision noting the replacement, although only its Matrix block changed');
    check($result['withoutRevision'] === 1, 'the asset is counted as changed without revision');
    $op = $operation($result['operationId']);
    $owners = array_column($op->getRevisions(), null, 'ownerId');
    check(!empty($owners[$owner->id]['revisionBeforeId']) && $owners[$owner->id]['support'] === ReplaceService::REVISIONS_YES, 'the operation remembers the owner\'s revision from before');
    check(($owners[$unversioned->id]['support'] ?? null) === ReplaceService::REVISIONS_UNSUPPORTED && empty($owners[$unversioned->id]['revisionBeforeId']), 'the asset is recorded as not supporting revisions');
    check($op->canRevertToRevisions(), 'the operation offers "revert to revisions"');

    // --- someone edits the owner afterwards
    sleep(1);
    $edited = Entry::find()->id($owner->id)->one() ?? throw new RuntimeException('owner');
    $edited->title = 'RV nested owner (edited later)';
    Elements::saveElement($edited) or fail($edited);
    flushQueue();

    // --- revert to revisions: the entry goes back to its revision (later edit discarded), the asset is swapped back
    sleep(1);
    $rb = ReplaceService::rollback($op, ReplaceService::ROLLBACK_REVISIONS);
    flushQueue();
    $reverted = Entry::find()->id($owner->id)->one();
    check($rb['reverted'] === 1 && $blockTargets() === [(int)$c->id], 'the owner is reverted to its revision: the Matrix block points to Page C again (' . json_encode($rb) . ')');
    check((string)$reverted === 'RV nested owner', 'reverting discards the later edit (title is back)');
    check(in_array('RV asset', relatingTitles($c), true) && !in_array('RV asset', relatingTitles($target), true), 'the asset (no revisions) is swapped back instead');
    check(in_array('RV nested owner', relatingTitles($c), true) && !in_array('RV nested owner', relatingTitles($target), true), 'the relations cache follows the revert immediately');
    check($operation($op->id)->status === Replacement::STATUS_ROLLED_BACK, 'the operation is marked rolled back');
    $op->delete();

    // --- swap back on a nested change also gets an owner revision
    sleep(1);
    $again = ReplaceService::replace($c, $target, [(int)$owner->id]);
    flushQueue();
    sleep(1);
    $countBefore = count($revisions((int)$owner->id));
    ReplaceService::rollback($operation($again['operationId']));
    flushQueue();
    $last = $revisions((int)$owner->id);
    check(count($last) === $countBefore + 1 && str_contains((string)end($last)['notes'], 'rolled back'), 'swapping back inside a Matrix block also makes an owner revision');
    $operation($again['operationId'])->delete();

    // --- same-second saves: Craft skips such revisions, the plugin must not rely on them (no sleep on purpose)
    $fast = new Entry(['sectionId' => $pages->id, 'typeId' => $pageType->id, 'title' => 'RV fast source']);
    $fast->setFieldValue('relatedEntries', [$c->id]);
    Elements::saveElement($fast) or fail($fast);
    flushQueue();
    $fastTargets = fn(): array => array_map('intval', Entry::find()->id($fast->id)->one()?->getFieldValue('relatedEntries')->ids() ?? []);
    $first = ReplaceService::replace($c, $target, [(int)$fast->id]);
    ReplaceService::rollback($operation($first['operationId'])); // same second
    check($fastTargets() === [(int)$c->id], 'fast: replace + immediate rollback restore Page C');
    $second = ReplaceService::replace($c, $target, [(int)$fast->id]); // same second again
    check($fastTargets() === [(int)$target->id], 'fast: second replacement applied');
    $rb = ReplaceService::rollback($operation($second['operationId']), ReplaceService::ROLLBACK_REVISIONS);
    flushQueue();
    check($rb['reverted'] === 1 && $fastTargets() === [(int)$c->id], 'fast: revert to revisions returns to the state right before the second replacement (Page C), although every save happened within one second (' . json_encode($rb) . ')');
    $notes = array_column($revisions((int)$fast->id), 'notes');
    check(count(array_filter($notes, fn($n) => str_contains((string)$n, 'replaced “Page C” with “RV target”'))) === 2, 'fast: both replacements have their own revision despite the same second (' . implode(' | ', array_map('strval', $notes)) . ')');
    foreach ([$first['operationId'], $second['operationId']] as $opId) {
        Replacement::query()->find($opId)?->delete();
    }
    Elements::deleteElement($fast, true);

    // --- sections without versioning, and the hints
    $siteSettings = [];
    foreach (Sites::getAllSites() as $site) {
        $siteSettings[$site->id] = new SectionSiteSettings(['siteId' => $site->id, 'hasUrls' => false]);
    }
    $noVersioning = new Section(['name' => 'RV No versioning', 'handle' => 'rvNoVersioning', 'type' => SectionType::Channel, 'enableVersioning' => false, 'entryTypes' => [$pageType], 'siteSettings' => $siteSettings]);
    Sections::saveSection($noVersioning) or fail($noVersioning);
    $noVersioning = Sections::getSectionByHandle('rvNoVersioning') ?? throw new RuntimeException('rvNoVersioning');
    $plain = new Entry(['sectionId' => $noVersioning->id, 'typeId' => $pageType->id, 'title' => 'RV unversioned source']);
    $plain->setFieldValue('relatedEntries', [$c->id]);
    Elements::saveElement($plain) or fail($plain);
    flushQueue();
    check(ReplaceService::revisionSupport($plain) === ReplaceService::REVISIONS_VERSIONING_OFF, 'an entry in a section without versioning: versioning off');
    check(ReplaceService::revisionSupport($unversioned) === ReplaceService::REVISIONS_UNSUPPORTED && ReplaceService::revisionSupport($owner) === ReplaceService::REVISIONS_YES, 'assets: unsupported; versioned entries: yes');
    $coverage = ReplaceService::revisionCoverage((int)$c->id);
    check($coverage['versioningOff'] >= 1 && $coverage['unsupported'] >= 1 && $coverage['total'] > $coverage['versioningOff'] + $coverage['unsupported'], 'revisionCoverage counts usages without revisions by reason (' . json_encode($coverage) . ')');
    $r = ReplaceService::replace($c, $target, [(int)$plain->id]);
    flushQueue();
    check($r['withoutRevision'] === 1 && $revisions((int)$plain->id) === [], 'replacing in it works and is reported as without revision; no revision exists');
    check(str_contains(ReplaceController::resultMessage($r, 'Page C', 'RV target'), 'no revision'), 'the success message says so');
    $op = $operation($r['operationId']);
    check(!$op->canRevertToRevisions(), 'such an operation offers no "revert to revisions", only Roll back');
    ReplaceService::rollback($op);
    flushQueue();
    check(in_array('RV unversioned source', relatingTitles($c), true), 'Roll back works without revisions');
    $op->delete();

    $hint = withWebRequest(fn() => ElementRelationsField::revisionHintFor($c));
    check(str_contains($hint, 'keep no revision') || str_contains($hint, 'keeps no revision'), "the wizard's review mentions the usages without revisions ($hint)");

    // --- cleanup
    foreach ([$plain, $owner, $target, $unversioned] as $element) {
        Elements::deleteElement($element, true);
    }
    Sections::deleteSection($noVersioning);
    Volumes::deleteVolume($volume);
    Plugins::switchEdition('element-relations', $editionBefore);
    flushQueue();
};
