<?php

declare(strict_types=1);

namespace internetztube\elementRelations;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\DefineBehaviorsEvent;
use craft\events\ModelEvent;
use craft\events\PluginEvent;
use craft\base\conditions\BaseCondition;
use craft\elements\conditions\ElementCondition;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\log\MonologTarget;
use craft\services\UserPermissions;
use Psr\Log\LogLevel;
use craft\events\RegisterConditionRulesEvent;
use craft\events\RegisterElementActionsEvent;
use craft\services\Fields;
use craft\services\Plugins;
use craft\services\Utilities;
use craft\helpers\Html;
use internetztube\elementRelations\behaviors\ElementQueryBehavior;
use internetztube\elementRelations\elements\RestrictedUsage;
use internetztube\elementRelations\elements\Usage;
use internetztube\elementRelations\elements\actions\ReplaceRelations;
use internetztube\elementRelations\elements\conditions\UsedConditionRule;
use internetztube\elementRelations\helpers\ElementSummary;
use internetztube\elementRelations\fields\ElementRelationsField;
use internetztube\elementRelations\jobs\GenerateResaveAllElementRelationsJobsJob;
use internetztube\elementRelations\models\SettingsModel;
use internetztube\elementRelations\services\ExtractorService;
use internetztube\elementRelations\services\QueueElementsForRefreshService;
use internetztube\elementRelations\utilities\ElementRelationsUtility;
use internetztube\elementRelations\services\extractors\GlobalUsageSeomaticService;
use yii\base\Application;
use yii\db\ActiveRecord;
use yii\base\Event;

/**
 * @method SettingsModel getSettings()
 */
class ElementRelations extends Plugin
{
    public static ElementRelations $plugin;
    public string $schemaVersion = "1.0.13";

    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    /** Element types that get the Pro "Replace everywhere…" index action (plugin classes may be absent). */
    public const PRO_ELEMENT_TYPES = [
        \craft\elements\Asset::class,
        \craft\elements\Entry::class,
        \craft\elements\Category::class,
        \craft\elements\Tag::class,
        'craft\\commerce\\elements\\Product',
    ];

    /**
     * Feature flags from `config/element-relations.php` (`'features' => ['editions' => true]`). Read from the file, not
     * the settings model, because Craft asks for editions() before the plugin instance exists.
     * @return array<string, mixed>
     */
    public static function features(): array
    {
        $config = Craft::$app->getConfig()->getConfigFromFile('element-relations');
        $features = is_array($config) ? ($config['features'] ?? []) : [];
        return is_array($features) ? $features : [];
    }

    /**
     * Lite only until the `editions` feature flag is on.
     * @return list<string>
     */
    public static function editions(): array
    {
        return self::editionsFor(self::features());
    }

    /**
     * @param array<string, mixed> $features
     * @return list<string>
     */
    public static function editionsFor(array $features): array
    {
        return !empty($features['editions']) ? [self::EDITION_LITE, self::EDITION_PRO] : [self::EDITION_LITE];
    }

    /** Pro user permissions (admins have them implicitly). */
    public const PERMISSION_REPLACE = 'element-relations-replace';
    public const PERMISSION_ROLLBACK = 'element-relations-rollback';
    public const PERMISSION_IN_USE = 'element-relations-delete-in-use';

    /** Pro and allowed to replace references (console runs without a user are trusted). */
    public static function canReplace(?\craft\elements\User $user = null): bool
    {
        return self::isPro() && self::allowed(self::PERMISSION_REPLACE, $user);
    }

    /** Pro and allowed to roll back replacements. */
    public static function canRollBack(?\craft\elements\User $user = null): bool
    {
        return self::isPro() && self::allowed(self::PERMISSION_ROLLBACK, $user);
    }

    /** Roll back this operation: own replacements, admins all of them. */
    public static function canRollBackOperation(\internetztube\elementRelations\records\ReplacementRecord $operation, ?\craft\elements\User $user = null): bool
    {
        $user ??= Craft::$app->getUser()->getIdentity();
        if (!self::canRollBack($user)) {
            return false;
        }
        return !$user || $user->admin || (int)$operation->userId === (int)$user->id;
    }

    /** Control panel actions that edit, delete or change the status of an element (Craft's queue runner and settings
     * changes, e.g. deleting a section with its entries, are not among them). */
    private const IN_USE_GUARDED_CONTROLLERS = ['elements', 'element-indexes', 'delete-elements', 'assets', 'users'];

    /**
     * Pro: whether elements in use are protected in this request (services/InUseGuard): the control panel's element
     * actions, for users without the "Delete or disable elements in use" permission. Console, queue jobs, migrations,
     * settings changes and site requests never are.
     */
    public static function guardsInUse(?\craft\elements\User $user = null): bool
    {
        $request = Craft::$app->getRequest();
        if (!self::isPro() || !$request instanceof \craft\web\Request || !$request->getIsCpRequest()) {
            return false;
        }
        if (!in_array($request->getActionSegments()[0] ?? null, self::IN_USE_GUARDED_CONTROLLERS, true)) {
            return false;
        }
        return !self::mayDeleteInUse($user);
    }

    /** Whether the user may delete or disable elements in use (admins may; Lite has no such limit). */
    public static function mayDeleteInUse(?\craft\elements\User $user = null): bool
    {
        if (!self::isPro()) {
            return true;
        }
        $user ??= Craft::$app->getUser()->getIdentity();
        return $user !== null && ($user->admin || $user->can(self::PERMISSION_IN_USE));
    }

    private static function allowed(string $permission, ?\craft\elements\User $user): bool
    {
        $user ??= Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return Craft::$app->getRequest()->getIsConsoleRequest();
        }
        return $user->admin || $user->can($permission);
    }

    /** Gate for Pro-only behaviour: the flag must be on and the installed edition must be Pro. */
    public static function isPro(): bool
    {
        return in_array(self::EDITION_PRO, self::editions(), true) && self::getInstance()?->is(self::EDITION_PRO) === true;
    }

    public bool $hasCpSettings = true;
    public bool $hasCpSection = false;

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        // own log file: storage/logs/element-relations-<date>.log (extractor errors, replacements, rollbacks)
        Craft::getLogger()->dispatcher->targets['element-relations'] = new MonologTarget([
            'name' => 'element-relations',
            'categories' => ['element-relations'],
            'level' => LogLevel::INFO,
            'logContext' => false,
            'allowLineBreaks' => false,
        ]);

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'internetztube\elementRelations\console\controllers';
        }

        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function (RegisterComponentTypesEvent $event): void {
            $event->types[] = ElementRelationsField::class;
        });

        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, function (RegisterComponentTypesEvent $event): void {
            $event->types[] = ElementRelationsUtility::class;
        });

        // `usesElement` element query param; a fixed name so Craft's ClonefixTrait keeps it on cloned queries
        Event::on(ElementQuery::class, Query::EVENT_DEFINE_BEHAVIORS, function (DefineBehaviorsEvent $event): void {
            $event->behaviors['elementRelations'] = ElementQueryBehavior::class;
        });

        // columns of the field's Usage index (one list across all element types, see elements/Usage.php)
        Event::on(Element::class, Element::EVENT_DEFINE_ATTRIBUTE_HTML, function (DefineAttributeHtmlEvent $event): void {
            /** @var Element $element */
            $element = $event->sender;
            if ($element instanceof RestrictedUsage) {
                $event->html = match ($event->attribute) {
                    Usage::ATTR_TYPE => is_subclass_of($element->realType, ElementInterface::class) ? Html::encode($element->realType::displayName()) : '',
                    Usage::ATTR_SITE => ElementSummary::sitesHtml($element),
                    default => '',
                };
                return;
            }
            $event->html = match ($event->attribute) {
                Usage::ATTR_TYPE => Html::encode($element::displayName()),
                Usage::ATTR_WHERE => Html::encode(ElementSummary::where($element)),
                Usage::ATTR_SITE => ElementSummary::sitesHtml($element),
                Usage::ATTR_FIELD => ElementSummary::viaHtml($element),
                // a Usage index mixes element types: a column this type doesn't have (Authors on an asset) stays empty
                // instead of Craft reading an unknown property
                default => isset(Usage::$via[(int)$element->id])
                    && !isset($element::tableAttributes()[$event->attribute])
                    && !str_contains($event->attribute, ':')
                    ? '' : $event->html,
            };
        });


        // type and site dropdowns in the toolbar of the field's index (Craft's toolbar template offers this hook, nothing else)
        Craft::$app->getView()->hook('cp.elements.toolbar', function (array &$context): string {
            if (($context['elementType'] ?? null) !== Usage::class) {
                return '';
            }
            return implode('', array_map(fn(array $menu): string => Craft::$app->getView()->renderTemplate('element-relations/_field/filter-menu', [
                'menu' => $menu,
                'id' => 'element-relations-menu-' . mt_rand(),
            ]), ElementRelationsField::$toolbarMenus));
        });

        // Pro: a "Used anywhere" condition rule (filter HUD, custom sources), an "Unused" source on asset/category/tag
        // indexes and a "Replace everywhere…" action on indexes.
        // Registered unconditionally and decided per call, so switching the edition needs no restart.
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function (RegisterUserPermissionsEvent $event): void {
            if (self::isPro()) {
                $event->permissions[] = [
                    'heading' => $this->name,
                    'permissions' => [
                        self::PERMISSION_REPLACE => ['label' => Craft::t('element-relations', 'permission-replace')],
                        self::PERMISSION_ROLLBACK => ['label' => Craft::t('element-relations', 'permission-rollback')],
                        self::PERMISSION_IN_USE => ['label' => Craft::t('element-relations', 'permission-in-use'), 'info' => Craft::t('element-relations', 'permission-in-use-info')],
                    ],
                ];
            }
        });
        foreach (self::PRO_ELEMENT_TYPES as $class) {
            if (!class_exists($class)) {
                continue;
            }
            Event::on($class, Element::EVENT_REGISTER_ACTIONS, function (RegisterElementActionsEvent $event): void {
                if (self::canReplace()) {
                    $event->actions[] = ReplaceRelations::class;
                }
            });
        }
        Event::on(ElementCondition::class, BaseCondition::EVENT_REGISTER_CONDITION_RULES, function (RegisterConditionRulesEvent $event): void {
            if (self::isPro()) {
                $event->conditionRules[] = UsedConditionRule::class;
            }
        });

        // Non-element usages: rewrite their cache rows whenever the plugin settings they live in change
        if (class_exists(\nystudio107\seomatic\records\MetaBundle::class)) {
            foreach ([ActiveRecord::EVENT_AFTER_INSERT, ActiveRecord::EVENT_AFTER_UPDATE, ActiveRecord::EVENT_AFTER_DELETE] as $eventName) {
                Event::on(\nystudio107\seomatic\records\MetaBundle::class, $eventName, fn() => GlobalUsageSeomaticService::refresh());
            }
        }

        // what a user may view among the usages is cached; forget it whenever that could change: a usage (relating
        // element) or a user saved, deleted or restored, group assignments, project config (sections, volumes, group
        // permissions); new or changed relations reset it in ExtractorService
        $forgetPermissions = fn() => \internetztube\elementRelations\elements\db\UsageQuery::resetPermissionCache();
        $forgetIfUsage = function (\craft\events\ElementEvent $event) use ($forgetPermissions): void {
            $element = $event->element;
            $isUsage = $element instanceof \craft\elements\User || (new \craft\db\Query())
                ->from('{{%elementrelations_cache}}')
                ->where(['sourcePrimaryOwnerId' => $element->id])
                ->exists();
            if ($isUsage) {
                $forgetPermissions();
            }
        };
        foreach ([\craft\services\Elements::EVENT_AFTER_SAVE_ELEMENT, \craft\services\Elements::EVENT_AFTER_DELETE_ELEMENT, \craft\services\Elements::EVENT_AFTER_RESTORE_ELEMENT] as $eventName) {
            Event::on(\craft\services\Elements::class, $eventName, $forgetIfUsage);
        }
        Event::on(\craft\services\Users::class, \craft\services\Users::EVENT_AFTER_ASSIGN_USER_TO_GROUPS, $forgetPermissions);
        foreach ([\craft\services\ProjectConfig::EVENT_ADD_ITEM, \craft\services\ProjectConfig::EVENT_UPDATE_ITEM, \craft\services\ProjectConfig::EVENT_REMOVE_ITEM] as $eventName) {
            Event::on(\craft\services\ProjectConfig::class, $eventName, $forgetPermissions);
        }

        Event::on(Element::class, Element::EVENT_AFTER_SAVE, function (ModelEvent $event): void {
            /** @var Element $element */
            $element = $event->sender;
            // Revisions are never shown as relation sources, so don't waste queue time and cache rows on them.
            if ($element->getIsRevision()) {
                return;
            }
            // Only changed custom fields can change field relations (Craft tracks them per save, incl. propagation to
            // other sites and untouched nested Matrix entries, which are saved along with their owner). Elements read
            // by an element-level extractor (user photo, ...) have no such signal and always refresh.
            if (
                !$event->isNew
                && !$element->isNewForSite
                && !$element->getDirtyFields()
                && !ExtractorService::hasElementExtractor($element)
            ) {
                return;
            }
            if (ExtractorService::hasElementExtractor($element)) {
                // their data is not per site (a Formie form's settings, a node's target) and Craft saves only one site
                foreach (\craft\helpers\ElementHelper::supportedSitesForElement($element) as $site) {
                    QueueElementsForRefreshService::registerForRefresh((int)$element->id, (int)$site['siteId']);
                }
                return;
            }
            QueueElementsForRefreshService::registerForRefresh((int)$element->id, (int)$element->siteId);
        });

        Event::on(Element::class, Element::EVENT_BEFORE_DELETE, function (ModelEvent $event): void {
            /** @var Element $element */
            $element = $event->sender;
            // A trashed element can be restored, so its usages stay (lists and counts skip trashed elements, the list's
            // "Trashed" status shows them; rows of elements Craft's garbage collection removes later are never joined).
            // Not for nested elements: Craft trashes a Matrix block's entry when it is removed from its owner, whose
            // usage it would otherwise still be; restoring the owner refreshes them (below).
            $nested = $element instanceof \craft\base\NestedElementInterface && $element->getPrimaryOwnerId() !== null;
            if ($element->hardDelete || $nested) {
                ExtractorService::forgetElement($element); // every site: a deleted element is gone in all of them
            }
        });
        // deleted for one site: that site's rows go, the other sites keep theirs
        Event::on(\craft\services\Elements::class, \craft\services\Elements::EVENT_AFTER_DELETE_FOR_SITE, function (\craft\events\ElementEvent $event): void {
            ExtractorService::forgetElement($event->element, (int)$event->element->siteId);
        });
        Event::on(\craft\services\Elements::class, \craft\services\Elements::EVENT_AFTER_RESTORE_ELEMENT, function (\craft\events\ElementEvent $event): void {
            $element = $event->element;
            if (!$element instanceof \craft\base\NestedElementInterface || $element->getPrimaryOwnerId() === null) {
                \internetztube\elementRelations\services\ReplaceService::refreshOwnerTree((int)$element->id); // its nested elements' rows
            }
        });

        // Pro: elements in use can't be deleted or taken offline in the control panel without a permission
        \internetztube\elementRelations\services\InUseGuard::register();

        $pluginEnableCallback = function (PluginEvent $event): void {
            if (!($event->plugin instanceof ElementRelations)) {
                return;
            }
            Craft::$app->getQueue()->priority(1021)->push(new GenerateResaveAllElementRelationsJobsJob());
        };
        Event::on(Plugins::class, Plugins::EVENT_AFTER_ENABLE_PLUGIN, $pluginEnableCallback);
        Event::on(Plugins::class, Plugins::EVENT_AFTER_INSTALL_PLUGIN, $pluginEnableCallback);

        Event::on(Application::class, Application::EVENT_AFTER_REQUEST, function (): void {
            QueueElementsForRefreshService::destruct();
        });
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'element-relations/settings',
            ['settings' => $this->getSettings()]
        );
    }

    protected function createSettingsModel(): ?Model
    {
        return new SettingsModel();
    }
}
