<?php

declare(strict_types=1);

/**
 * What other plugins hook into: a provider of non-element usages (plugin settings), an element extractor that breaks
 * (it must not take the refresh down in production, and must in debug mode), and the condition rules matched against
 * single elements (conditional field layouts, custom sources). Also the plugin's own type metadata.
 */

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Field;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\Plugin\Events\PluginDisabled;
use CraftCms\Cms\Plugin\Events\PluginEnabled;
use CraftCms\Cms\Plugin\Plugin;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Str;
use CraftCms\Cms\Support\Url;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Elements\Conditions\UsedConditionRule;
use Internetztube\ElementRelations\Elements\Conditions\UsesElementConditionRule;
use Internetztube\ElementRelations\Elements\Queries\UsageQuery;
use Internetztube\ElementRelations\Elements\Usage;
use Internetztube\ElementRelations\Events\ElementExtractorsResolving;
use Internetztube\ElementRelations\Events\FieldExtractorsResolving;
use Internetztube\ElementRelations\Events\GlobalUsageProvidersResolving;
use Internetztube\ElementRelations\Extractors\InterfaceElementExtractor;
use Internetztube\ElementRelations\Extractors\InterfaceElementReplacer;
use Internetztube\ElementRelations\Extractors\InterfaceFieldExtractor;
use Internetztube\ElementRelations\Extractors\InterfaceFieldReplacer;
use Internetztube\ElementRelations\Extractors\InterfaceGlobalUsage;
use Internetztube\ElementRelations\Fields\ElementRelationsField;
use Internetztube\ElementRelations\Models\CacheRecord;
use Internetztube\ElementRelations\Models\GlobalUsage;
use Internetztube\ElementRelations\Models\RelationsModel;
use Internetztube\ElementRelations\Services\ExtractorService;
use Internetztube\ElementRelations\Services\InUseGuard;
use Internetztube\ElementRelations\Services\QueueElementsForRefreshService;
use Internetztube\ElementRelations\Services\ReplaceService;

use function CraftCms\Cms\t;

if (!class_exists('SmokeSettingsUsage')) {
    /** A field extractor without a replacer, as a third party might register one: replacing passes it by. */
    final class SmokeReadOnlyExtractor implements InterfaceFieldExtractor
    {
        public static function getRelations(Field $field, ElementInterface $element, CacheRecord $baseRecord): array|false
        {
            return false;
        }
    }

    /** A plugin's settings that use an asset, as a third-party provider would register them. */
    final class SmokeSettingsUsage implements InterfaceGlobalUsage
    {
        public static ?int $assetId = null;

        public static function type(): string
        {
            return 'smoke-settings';
        }

        public static function refresh(): void
        {
            DB::table(CacheRecord::TABLE)->where('type', self::type())->delete();
            if (self::$assetId) {
                DB::table(CacheRecord::TABLE)->insert([
                    'targetElementId' => self::$assetId, 'targetSiteId' => Sites::getPrimarySite()->id,
                    'type' => self::type(), 'globalKey' => 'logo', 'dateCreated' => now(), 'dateUpdated' => now(), 'uid' => Str::uuid()->toString(),
                ]);
            }
        }

        public static function usages(array $rows): array
        {
            return array_map(fn(array $row): GlobalUsage => new GlobalUsage(provider: 'smoke', key: (string)$row['globalKey'], label: 'Smoke settings: logo', url: Url::cpUrl('settings/plugins/smoke'), icon: 'gear'), $rows);
        }
    }

    /** An element extractor that breaks, like one after an incompatible update of the plugin it reads. */
    final class SmokeBrokenExtractor implements InterfaceElementExtractor
    {
        public static function elementTypes(): array
        {
            return [Entry::class];
        }

        public static function getRelations(ElementInterface $element, CacheRecord $baseRecord): CacheRecord|array|false
        {
            throw new RuntimeException('incompatible version');
        }
    }
}

return function (array $ctx): void {
    $asset = $ctx['asset'];

    // --- a third-party provider of non-element usages
    Event::listen(fn(GlobalUsageProvidersResolving $event) => $event->types[] = SmokeSettingsUsage::class);
    RelationsModel::resetProviders();
    SmokeSettingsUsage::$assetId = (int)$asset->id;
    RelationsModel::refreshGlobalUsages();
    $model = new RelationsModel((int)$asset->id, (int)$asset->siteId);
    $ours = array_values(array_filter($model->getGlobalUsages(), fn(GlobalUsage $usage): bool => $usage->provider === 'smoke'));
    check(in_array(SmokeSettingsUsage::class, RelationsModel::globalUsageProviders(), true) && count($ours) === 1 && $ours[0]->getUiLabel() === 'Smoke settings: logo', 'GlobalUsageProvidersResolving adds a provider; its usage is listed');
    check($ours[0]->getId() === 'smoke:logo' && $ours[0]->getIcon() === 'gear' && str_contains((string)$ours[0]->getCpEditUrl(), 'settings/plugins/smoke') && GlobalUsage::get('smoke:logo') === null, 'a global usage has an id, icon and edit URL (and is not loaded by id)');
    $field = Fields::getFieldByHandle('relations');
    assert($field instanceof ElementRelationsField);
    $html = withWebRequest(fn(): string => $field->inputHtmlFor($model, $asset));
    check(str_contains($html, t('field-headline-global', category: 'element-relations')) && str_contains($html, 'Smoke settings: logo'), 'the field shows it under its own heading, as a chip');
    // the in-use guard names plugin settings, here the only usage of an asset nothing else uses
    $lonely = createAsset('unused-lonely.png');
    SmokeSettingsUsage::$assetId = (int)$lonely->id;
    RelationsModel::refreshGlobalUsages();
    $usages = InUseGuard::liveUsages($lonely);
    check($usages['count'] === 1 && $usages['titles'] === ['“Smoke settings: logo”'], 'the in-use guard names plugin settings that use the element (' . json_encode($usages, JSON_UNESCAPED_UNICODE) . ')');
    SmokeSettingsUsage::$assetId = null;
    RelationsModel::refreshGlobalUsages();
    check(!array_filter((new RelationsModel((int)$asset->id, (int)$asset->siteId))->getGlobalUsages(), fn(GlobalUsage $usage): bool => $usage->provider === 'smoke'), 'a refresh without the setting removes it');
    Event::forget(GlobalUsageProvidersResolving::class);
    RelationsModel::resetProviders();
    \CraftCms\Cms\Support\Facades\Elements::deleteElement($lonely, true);

    // --- a broken element extractor: logged and skipped in production, thrown in debug mode
    $page = Entry::find()->section('pages')->title('Page A1')->one() ?? throw new RuntimeException('A1');
    $rows = fn(): int => DB::table(CacheRecord::TABLE)->where('sourceElementId', $page->id)->count();
    $rowsBefore = $rows();
    Event::listen(fn(ElementExtractorsResolving $event) => $event->types[] = SmokeBrokenExtractor::class);
    ExtractorService::reset();
    $debug = config('app.debug');
    config(['app.debug' => false]);
    ExtractorService::refreshRelationsForElement($page);
    $rowsAfter = $rows();
    check($rowsAfter === $rowsBefore && $rowsBefore > 0, "production: a broken extractor is skipped, the element's other relations are kept ($rowsAfter rows)");
    config(['app.debug' => true]);
    $thrown = null;
    try {
        ExtractorService::refreshRelationsForElement($page);
    } catch (RuntimeException $e) {
        $thrown = $e->getMessage();
    }
    config(['app.debug' => $debug]);
    check($thrown === 'incompatible version', 'debug mode: the broken extractor throws, so its author sees it');
    Event::forget(ElementExtractorsResolving::class);
    ExtractorService::reset();

    // --- the condition rules on single elements
    $used = new UsedConditionRule(['value' => true]);
    $unusedAsset = Asset::find()->volume('test')->orderBy('elements.id desc')->one() ?? throw new RuntimeException('asset');
    $inUse = (new RelationsModel((int)$unusedAsset->id, (int)$unusedAsset->siteId))->getIsInUseFast([]);
    check($used->getLabel() !== '' && $used->matchElement($asset) && $used->matchElement($unusedAsset) === $inUse, '“Used anywhere” on: matches an element in use, not one that is unused');
    check((new UsedConditionRule(['value' => false]))->matchElement($asset) === false, '“Used anywhere” off: does not match an element in use');
    $target = $ctx['target'];
    $rule = new UsesElementConditionRule(['elementId' => $target->id]);
    $a1 = Entry::find()->title('Page A1')->one() ?? throw new RuntimeException('A1');
    $unrelated = Entry::find()->section('pages')->title('Page B')->one() ?? throw new RuntimeException('B');
    $sourceIds = (new RelationsModel((int)$target->id, (int)$target->siteId))->getSourceElementIds([]);
    check($rule->getLabel() !== '' && formText($rule->getForm()) === '' && $rule->matchElement($a1) === in_array((int)$a1->id, $sourceIds, true), 'the list rule matches what uses the element, and has nothing to show');
    check($rule->matchElement($unrelated) === in_array((int)$unrelated->id, $sourceIds, true), 'and not what does not use it');
    $sorted = function (array $ids): array {
        $ids = array_map('intval', $ids);
        sort($ids);
        return $ids;
    };
    $narrowed = Entry::find()->status(null);
    $narrowed->where(fn(Illuminate\Database\Query\Builder $query) => $rule->modifyQuery($query, $narrowed));
    check($sorted($narrowed->ids()) === $sorted(Entry::find()->status(null)->usesElement($target->id)->ids()), 'on a query, the list rule keeps what uses the element');
    // the refresh queue pushes a job as soon as a batch is full, not only at the end of the request
    $settings = ElementRelations::instance()?->getSettings() ?? throw new RuntimeException('settings');
    $batchSize = $settings->bulkRefreshBatchSize;
    $settings->bulkRefreshBatchSize = 2;
    $queueCount = fn(): int => DB::table('jobs')->count();
    flushQueue(); // nothing pending
    $queuedBefore = $queueCount();
    QueueElementsForRefreshService::registerForRefresh((int)$a1->id, (int)$a1->siteId);
    $afterOne = $queueCount();
    QueueElementsForRefreshService::registerForRefresh((int)$unrelated->id, (int)$unrelated->siteId);
    $settings->bulkRefreshBatchSize = $batchSize;
    check($afterOne === $queuedBefore && $queueCount() === $queuedBefore + 1, 'the refresh queue pushes a job once a batch is full (batch size 2)');
    flushQueue();
    check(UsesElementConditionRule::idsIn(json_encode(['conditionRules' => [['class' => UsesElementConditionRule::class, 'elementId' => $target->id]]])) === [(int)$target->id] && UsesElementConditionRule::idsIn('not json') === [], "a request's condition config, as JSON, gives the list's element");

    // --- every built-in replacer passes on what is not its own: a plain text field, an element type it doesn't read
    $plain = new PlainText(['handle' => 'smokePlain']);
    $claimed = [];
    foreach (ExtractorService::fieldExtractors() as $extractor) {
        if (is_subclass_of($extractor, InterfaceFieldReplacer::class) && $extractor::replace($plain, $a1, $target, $unrelated) !== null) {
            $claimed[] = $extractor;
        }
        $read = $extractor::getRelations($plain, $a1, new CacheRecord());
        if ($read !== false && $read !== []) {
            $claimed[] = "$extractor (reads it)";
        }
    }
    // (4.x took a category; Craft 6 has none: an asset, which no element extractor reads either)
    foreach (ExtractorService::elementExtractors() as $extractor) {
        if (is_subclass_of($extractor, InterfaceElementReplacer::class) && $extractor::replace($asset, $target, $unrelated) !== null) {
            $claimed[] = $extractor;
        }
    }
    check(!$claimed && !ReplaceService::replaceInField($plain, $a1, $target, $unrelated) && !ReplaceService::replaceInElement($asset, $target, $unrelated), 'every extractor and replacer passes on a field or element type it does not read: nothing is read or replaced there (' . implode(', ', $claimed) . ')');

    // --- extractors without a replacer are passed by when replacing (here: one of each, registered by events)
    Event::listen(fn(FieldExtractorsResolving $event) => array_unshift($event->types, SmokeReadOnlyExtractor::class));
    Event::listen(fn(ElementExtractorsResolving $event) => array_unshift($event->types, SmokeBrokenExtractor::class));
    ExtractorService::reset();
    $relationsField = Fields::getFieldByHandle('relatedEntries');
    assert($relationsField instanceof Field);
    $withEntry = Entry::find()->relatedTo(['targetElement' => $target, 'field' => 'relatedEntries'])->status(null)->one() ?? throw new RuntimeException('nothing relates to Page C via relatedEntries');
    $passed = ReplaceService::replaceInField($relationsField, $withEntry, $unrelated, $target) === false
        && ReplaceService::replaceInElement($withEntry, $unrelated, $target) === false;
    Event::forget(FieldExtractorsResolving::class);
    Event::forget(ElementExtractorsResolving::class);
    ExtractorService::reset();
    check($passed, 'an extractor without a replacer (field or element) is passed by when replacing, the built-in replacers still answer');

    // --- the field without an element (a new, unsaved one) and with a value that is not its model
    check($field->normalizeValue(null, null) === null && $field->serializeValue('not a model', null) === null && $field->getPreviewHtml(null, $a1) === '', 'the field has no value without a saved element, and shows nothing for one that is not its model');
    check(str_contains(withWebRequest(fn(): string => $field->inputHtmlFor(null, null)), t('field-hint-not-saved', category: 'element-relations')), 'an unsaved element: the field says it lists usages once saved');
    check(Fields::getAllFieldTypes()->contains(ElementRelationsField::class), 'the field type is offered in Craft\'s field settings');
    // another plugin enabled or disabled: no rebuild (only this plugin's own enable queues one)
    $other = new class (app()) extends Plugin {};
    $jobs = fn(): int => DB::table('jobs')->count();
    $queued = $jobs();
    event(new PluginEnabled($other));
    event(new PluginDisabled($other));
    check($jobs() === $queued && !Cache::has('element-relations:rebuild-on-enable'), 'enabling or disabling another plugin queues no rebuild');

    // --- a plugin removed while its elements still use the element: they are listed nowhere, so counted nowhere
    $numbers = function () use ($target): array {
        $model = new RelationsModel((int)$target->id, (int)$target->siteId);
        $list = Usage::find()->allSites()->status(null)->drafts(null)->usesElement($target->id);
        $editor = User::find()->username('jonas')->one();
        return [$model->getCountFast([]), $model->getCount([]), count($model->getElements([])), $list->count(), count($list->all()), array_sum($model->getCountsByType()), $editor ? UsageQuery::hiddenCount([(int)$target->id], $editor) : 0];
    };
    $before = $numbers();
    $ghostId = DB::table(Table::ELEMENTS)->insertGetId(['type' => 'Removed\\Plugin\\Thing', 'enabled' => true, 'archived' => false, 'dateCreated' => now(), 'dateUpdated' => now(), 'uid' => Str::uuid()->toString()]);
    DB::table(Table::ELEMENTS_SITES)->insert(['elementId' => $ghostId, 'siteId' => $target->siteId, 'enabled' => true, 'dateCreated' => now(), 'dateUpdated' => now(), 'uid' => Str::uuid()->toString()]);
    DB::table(CacheRecord::TABLE)->insert(['sourceElementId' => $ghostId, 'sourceSiteId' => $target->siteId, 'sourcePrimaryOwnerId' => $ghostId, 'targetElementId' => $target->id, 'targetSiteId' => $target->siteId, 'type' => 'field', 'dateCreated' => now(), 'dateUpdated' => now(), 'uid' => Str::uuid()->toString()]);
    UsageQuery::resetPermissionCache();
    RelationsModel::forgetPrefetched();
    $with = $numbers();
    DB::table(Table::ELEMENTS)->where('id', $ghostId)->delete();
    DB::table(CacheRecord::TABLE)->where('sourceElementId', $ghostId)->delete();
    UsageQuery::resetPermissionCache();
    check($with === $before && count(array_unique(array_slice($before, 0, 6))) === 1, 'an element of a removed plugin is neither listed nor counted: every number stays the same (' . json_encode([$before, $with]) . ')');

    // --- type metadata Craft shows in its settings
    check(ElementRelationsField::displayName() === 'Element Relations' && str_ends_with(ElementRelationsField::icon(), 'icon-mask.svg') && ElementRelationsField::isMultiInstance() === false, 'field type: name, icon, one per layout');
    check(Usage::lowerDisplayName() !== '' && Usage::pluralLowerDisplayName() !== '' && Usage::displayName() !== '', 'the usage list names its rows');
};
