<?php
/**
 * What other plugins hook into: a provider of non-element usages (plugin settings), an element extractor that breaks
 * (it must not take the refresh down in production, and must in dev mode), and the condition rules matched against
 * single elements (conditional field layouts, custom sources). Also the plugin's own type metadata.
 */

use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use internetztube\elementRelations\elements\conditions\UsedConditionRule;
use internetztube\elementRelations\elements\conditions\UsesElementConditionRule;
use internetztube\elementRelations\elements\Usage;
use internetztube\elementRelations\fields\ElementRelationsField;
use internetztube\elementRelations\models\GlobalUsage;
use internetztube\elementRelations\models\RelationsModel;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;
use internetztube\elementRelations\services\ExtractorService;
use internetztube\elementRelations\services\extractors\InterfaceElementExtractor;
use internetztube\elementRelations\services\extractors\InterfaceGlobalUsage;
use yii\base\Event;

if (!class_exists('SmokeSettingsUsage')) {
    /** A field extractor without a replacer, as a third party might register one: replacing passes it by. */
    final class SmokeReadOnlyExtractor implements internetztube\elementRelations\services\extractors\InterfaceFieldExtractor
    {
        public static function getRelations(craft\base\Field $field, ElementInterface $element, ElementRelationsCacheRecord $baseRecord): array|false
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
            $db = Craft::$app->getDb();
            $db->createCommand()->delete('{{%elementrelations_cache}}', ['type' => self::type()])->execute();
            if (self::$assetId) {
                $now = craft\helpers\Db::prepareDateForDb(new DateTime());
                $db->createCommand()->insert('{{%elementrelations_cache}}', [
                    'targetElementId' => self::$assetId, 'targetSiteId' => Craft::$app->getSites()->getPrimarySite()->id,
                    'type' => self::type(), 'globalKey' => 'logo', 'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => craft\helpers\StringHelper::UUID(),
                ])->execute();
            }
        }

        public static function usages(array $rows): array
        {
            return array_map(fn(array $row): GlobalUsage => new GlobalUsage(['provider' => 'smoke', 'key' => (string)$row['globalKey'], 'label' => 'Smoke settings: logo', 'url' => craft\helpers\UrlHelper::cpUrl('settings/plugins/smoke'), 'icon' => 'gear']), $rows);
        }
    }

    /** An element extractor that breaks, like one after an incompatible update of the plugin it reads. */
    final class SmokeBrokenExtractor implements InterfaceElementExtractor
    {
        public static function elementTypes(): array
        {
            return [Entry::class];
        }

        public static function getRelations(ElementInterface $element, ElementRelationsCacheRecord $baseRecord): ElementRelationsCacheRecord|array|false
        {
            throw new RuntimeException('incompatible version');
        }
    }
}

return function (array $ctx): void {
    $asset = $ctx['asset'];
    $providers = new ReflectionProperty(RelationsModel::class, 'providers');

    // --- a third-party provider of non-element usages
    $register = fn(craft\events\RegisterComponentTypesEvent $event) => $event->types[] = SmokeSettingsUsage::class;
    Event::on(RelationsModel::class, RelationsModel::EVENT_REGISTER_GLOBAL_USAGE_PROVIDERS, $register);
    $providers->setValue(null, null);
    SmokeSettingsUsage::$assetId = (int)$asset->id;
    RelationsModel::refreshGlobalUsages();
    $model = new RelationsModel((int)$asset->id, (int)$asset->siteId);
    $ours = array_values(array_filter($model->getGlobalUsages(), fn(GlobalUsage $usage): bool => $usage->provider === 'smoke'));
    check(in_array(SmokeSettingsUsage::class, RelationsModel::globalUsageProviders(), true) && count($ours) === 1 && $ours[0]->getUiLabel() === 'Smoke settings: logo', 'EVENT_REGISTER_GLOBAL_USAGE_PROVIDERS adds a provider; its usage is listed');
    check($ours[0]->getId() === 'smoke:logo' && $ours[0]->getIcon() === 'gear' && str_contains((string)$ours[0]->getCpEditUrl(), 'settings/plugins/smoke') && GlobalUsage::get('smoke:logo') === null, 'a global usage has an id, icon and edit URL (and is not loaded by id)');
    $field = Craft::$app->getFields()->getFieldByHandle('relations');
    $html = withWebRequest(fn() => $field->getInputHtml($model, $asset));
    check(str_contains($html, Craft::t('element-relations', 'field-headline-global')) && str_contains($html, 'Smoke settings: logo'), 'the field shows it under its own heading, as a chip');
    // the in-use guard names plugin settings, here the only usage of an asset nothing else uses
    $lonely = null;
    foreach (Asset::find()->status(null)->all() as $candidate) {
        if (!(new RelationsModel((int)$candidate->id, (int)$candidate->siteId))->getIsInUseFast([])) {
            $lonely = $candidate;
            break;
        }
    }
    $lonely ?? throw new RuntimeException('no unused asset');
    SmokeSettingsUsage::$assetId = (int)$lonely->id;
    RelationsModel::refreshGlobalUsages();
    $usages = internetztube\elementRelations\services\InUseGuard::liveUsages($lonely);
    check($usages['count'] === 1 && $usages['titles'] === ['“Smoke settings: logo”'], 'the in-use guard names plugin settings that use the element (' . json_encode($usages, JSON_UNESCAPED_UNICODE) . ')');
    SmokeSettingsUsage::$assetId = null;
    RelationsModel::refreshGlobalUsages();
    check(!array_filter((new RelationsModel((int)$asset->id, (int)$asset->siteId))->getGlobalUsages(), fn(GlobalUsage $usage): bool => $usage->provider === 'smoke'), 'a refresh without the setting removes it');
    Event::off(RelationsModel::class, RelationsModel::EVENT_REGISTER_GLOBAL_USAGE_PROVIDERS, $register);
    $providers->setValue(null, null);

    // --- a broken element extractor: logged and skipped in production, thrown in dev mode
    $page = Entry::find()->section('pages')->title('Page A1')->one();
    $rowsBefore = (int)(new craft\db\Query())->from('{{%elementrelations_cache}}')->where(['sourceElementId' => $page->id])->count();
    $broken = fn(craft\events\RegisterComponentTypesEvent $event) => $event->types[] = SmokeBrokenExtractor::class;
    Event::on(ExtractorService::class, ExtractorService::EVENT_REGISTER_ELEMENT_EXTRACTORS, $broken);
    ExtractorService::reset();
    $general = Craft::$app->getConfig()->getGeneral();
    $devMode = $general->devMode;
    $general->devMode = false;
    ExtractorService::refreshRelationsForElement($page);
    $rowsAfter = (int)(new craft\db\Query())->from('{{%elementrelations_cache}}')->where(['sourceElementId' => $page->id])->count();
    check($rowsAfter === $rowsBefore && $rowsBefore > 0, "production: a broken extractor is skipped, the element's other relations are kept ($rowsAfter rows)");
    $general->devMode = true;
    $thrown = null;
    try {
        ExtractorService::refreshRelationsForElement($page);
    } catch (RuntimeException $e) {
        $thrown = $e->getMessage();
    }
    $general->devMode = $devMode;
    check($thrown === 'incompatible version', 'dev mode: the broken extractor throws, so its author sees it');
    Event::off(ExtractorService::class, ExtractorService::EVENT_REGISTER_ELEMENT_EXTRACTORS, $broken);
    ExtractorService::reset();

    // --- the condition rules on single elements
    $used = new UsedConditionRule(['value' => true]);
    $unusedAsset = Asset::find()->volume('test')->orderBy(['elements.id' => SORT_DESC])->one();
    $inUse = (new RelationsModel((int)$unusedAsset->id, (int)$unusedAsset->siteId))->getIsInUseFast([]);
    check($used->getLabel() !== '' && $used->matchElement($asset) && $used->matchElement($unusedAsset) === $inUse, '“Used anywhere” on: matches an element in use, not one that is unused');
    check((new UsedConditionRule(['value' => false]))->matchElement($asset) === false, '“Used anywhere” off: does not match an element in use');
    $target = $ctx['target'];
    $rule = new UsesElementConditionRule(['elementId' => $target->id]);
    $a1 = Entry::find()->title('Page A1')->one();
    $unrelated = Entry::find()->section('pages')->title('Page B')->one();
    check($rule->getLabel() !== '' && $rule->getHtml() === '' && $rule->matchElement($a1) === in_array((int)$a1->id, (new RelationsModel((int)$target->id, (int)$target->siteId))->getSourceElementIds([]), true), 'the list rule matches what uses the element, and has nothing to show');
    check($rule->matchElement($unrelated) === in_array((int)$unrelated->id, (new RelationsModel((int)$target->id, (int)$target->siteId))->getSourceElementIds([]), true), 'and not what does not use it');
    $narrowed = Entry::find()->status(null);
    $rule->modifyQuery($narrowed);
    $sorted = function (array $ids): array { $ids = array_map('intval', $ids); sort($ids); return $ids; };
    check($sorted($narrowed->ids()) === $sorted(Craft::configure(Entry::find()->status(null), ['usesElement' => $target->id])->ids()), 'on a query, the list rule keeps what uses the element');
    // the refresh queue pushes a job as soon as a batch is full, not only at the end of the request
    $settings = internetztube\elementRelations\ElementRelations::$plugin->getSettings();
    $batchSize = $settings->bulkRefreshBatchSize;
    $settings->bulkRefreshBatchSize = 2;
    $queueCount = fn(): int => (int)(new craft\db\Query())->from(craft\db\Table::QUEUE)->count();
    $queuedBefore = $queueCount();
    internetztube\elementRelations\services\QueueElementsForRefreshService::registerForRefresh((int)$a1->id, (int)$a1->siteId);
    $afterOne = $queueCount();
    internetztube\elementRelations\services\QueueElementsForRefreshService::registerForRefresh((int)$unrelated->id, (int)$unrelated->siteId);
    $settings->bulkRefreshBatchSize = $batchSize;
    check($afterOne === $queuedBefore && $queueCount() === $queuedBefore + 1, 'the refresh queue pushes a job once a batch is full (batch size 2)');
    flushQueue();
    check(UsesElementConditionRule::idsIn(json_encode(['conditionRules' => [['class' => UsesElementConditionRule::class, 'elementId' => $target->id]]])) === [(int)$target->id] && UsesElementConditionRule::idsIn('not json') === [], "a request's condition config, as JSON, gives the list's element");

    // --- every built-in replacer passes on what is not its own: a plain text field, an element type it doesn't read
    $plain = new craft\fields\PlainText(['handle' => 'smokePlain']);
    $claimed = [];
    foreach (ExtractorService::fieldExtractors() as $extractor) {
        if (is_subclass_of($extractor, internetztube\elementRelations\services\extractors\InterfaceFieldReplacer::class) && $extractor::replace($plain, $a1, $target, $unrelated) !== null) {
            $claimed[] = $extractor;
        }
        $read = $extractor::getRelations($plain, $a1, new ElementRelationsCacheRecord());
        if ($read !== false && $read !== []) {
            $claimed[] = "$extractor (reads it)";
        }
    }
    $category = craft\elements\Category::find()->one() ?? throw new RuntimeException('no category');
    foreach (ExtractorService::elementExtractors() as $extractor) {
        if (is_subclass_of($extractor, internetztube\elementRelations\services\extractors\InterfaceElementReplacer::class) && $extractor::replace($category, $target, $unrelated) !== null) {
            $claimed[] = $extractor;
        }
    }
    check(!$claimed && !internetztube\elementRelations\services\ReplaceService::replaceInField($plain, $a1, $target, $unrelated) && !internetztube\elementRelations\services\ReplaceService::replaceInElement($category, $target, $unrelated), 'every extractor and replacer passes on a field or element type it does not read: nothing is read or replaced there (' . implode(', ', $claimed) . ')');

    // --- extractors without a replacer are passed by when replacing (here: one of each, registered by events)
    $readOnly = fn(craft\events\RegisterComponentTypesEvent $event) => array_unshift($event->types, SmokeReadOnlyExtractor::class);
    $brokenFirst = fn(craft\events\RegisterComponentTypesEvent $event) => array_unshift($event->types, SmokeBrokenExtractor::class);
    Event::on(ExtractorService::class, ExtractorService::EVENT_REGISTER_FIELD_EXTRACTORS, $readOnly);
    Event::on(ExtractorService::class, ExtractorService::EVENT_REGISTER_ELEMENT_EXTRACTORS, $brokenFirst);
    ExtractorService::reset();
    $relationsField = Craft::$app->getFields()->getFieldByHandle('relatedEntries');
    $withEntry = Entry::find()->relatedTo(['targetElement' => $target, 'field' => 'relatedEntries'])->status(null)->one() ?? throw new RuntimeException('nothing relates to Page C via relatedEntries');
    $passed = internetztube\elementRelations\services\ReplaceService::replaceInField($relationsField, $withEntry, $unrelated, $target) === false
        && internetztube\elementRelations\services\ReplaceService::replaceInElement($withEntry, $unrelated, $target) === false;
    Event::off(ExtractorService::class, ExtractorService::EVENT_REGISTER_FIELD_EXTRACTORS, $readOnly);
    Event::off(ExtractorService::class, ExtractorService::EVENT_REGISTER_ELEMENT_EXTRACTORS, $brokenFirst);
    ExtractorService::reset();
    check($passed, 'an extractor without a replacer (field or element) is passed by when replacing, the built-in replacers still answer');

    // --- the field without an element (a new, unsaved one) and with a value that is not its model
    $relationsType = Craft::$app->getFields()->getFieldByHandle('relations');
    check($relationsType->normalizeValue(null, null) === null && $relationsType->serializeValue('not a model') === null && $relationsType->getPreviewHtml(null, $a1) === '', 'the field has no value without a saved element, and shows nothing for one that is not its model');
    check(str_contains(withWebRequest(fn() => $relationsType->getInputHtml(null, null)), Craft::t('element-relations', 'field-hint-not-saved')), 'an unsaved element: the field says it lists usages once saved');
    check(in_array(ElementRelationsField::class, Craft::$app->getFields()->getAllFieldTypes(), true), 'the field type is offered in Craft\'s field settings');
    // another plugin enabled: no rebuild (only this plugin's own install or enable queues one)
    $jobs = fn(): int => (int)(new craft\db\Query())->from(craft\db\Table::QUEUE)->count();
    $queued = $jobs();
    Event::trigger(craft\services\Plugins::class, craft\services\Plugins::EVENT_AFTER_ENABLE_PLUGIN, new craft\events\PluginEvent(['plugin' => Craft::$app->getPlugins()->getPlugin('seomatic')]));
    check($jobs() === $queued, 'enabling another plugin queues no rebuild');

    // --- a plugin removed while its elements still use the element: they are listed nowhere, so counted nowhere
    $db = Craft::$app->getDb();
    $numbers = function () use ($target): array {
        $model = new RelationsModel((int)$target->id, (int)$target->siteId);
        $list = Craft::configure(Usage::find()->allSites()->status(null)->drafts(null), ['usesElement' => $target->id]);
        $editor = craft\elements\User::find()->username('jonas')->one();
        return [$model->getCountFast([]), $model->getCount([]), count($model->getElements([])), (int)$list->count(), count($list->all()), array_sum($model->getCountsByType([])), $editor ? internetztube\elementRelations\elements\db\UsageQuery::hiddenCount([(int)$target->id], $editor) : 0];
    };
    $before = $numbers();
    $now = craft\helpers\Db::prepareDateForDb(new DateTime());
    $db->createCommand()->insert(craft\db\Table::ELEMENTS, ['type' => 'Removed\\Plugin\\Thing', 'enabled' => true, 'archived' => false, 'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => craft\helpers\StringHelper::UUID()])->execute();
    $ghostId = (int)$db->getLastInsertID(craft\db\Table::ELEMENTS);
    $db->createCommand()->insert(craft\db\Table::ELEMENTS_SITES, ['elementId' => $ghostId, 'siteId' => $target->siteId, 'enabled' => true, 'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => craft\helpers\StringHelper::UUID()])->execute();
    $db->createCommand()->insert('{{%elementrelations_cache}}', ['sourceElementId' => $ghostId, 'sourceSiteId' => $target->siteId, 'sourcePrimaryOwnerId' => $ghostId, 'targetElementId' => $target->id, 'targetSiteId' => $target->siteId, 'type' => 'field', 'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => craft\helpers\StringHelper::UUID()])->execute();
    internetztube\elementRelations\elements\db\UsageQuery::resetPermissionCache();
    $with = $numbers();
    $db->createCommand()->delete(craft\db\Table::ELEMENTS, ['id' => $ghostId])->execute();
    $db->createCommand()->delete('{{%elementrelations_cache}}', ['sourceElementId' => $ghostId])->execute();
    internetztube\elementRelations\elements\db\UsageQuery::resetPermissionCache();
    check($with === $before && count(array_unique(array_slice($before, 0, 6))) === 1, 'an element of a removed plugin is neither listed nor counted: every number stays the same (' . json_encode([$before, $with]) . ')');

    // --- type metadata Craft shows in its settings
    check(ElementRelationsField::displayName() === 'Element Relations' && str_ends_with(ElementRelationsField::icon(), 'icon-mask.svg') && ElementRelationsField::isMultiInstance() === false, 'field type: name, icon, one per layout');
    check(Usage::lowerDisplayName() !== '' && Usage::pluralLowerDisplayName() !== '' && Usage::displayName() !== '', 'the usage list names its rows');
};
