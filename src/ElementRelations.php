<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Condition\Events\ConditionRulesResolving;
use CraftCms\Cms\Element\Conditions\ElementCondition;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Contracts\NestedElementInterface;
use CraftCms\Cms\Element\ElementHelper;
use CraftCms\Cms\Element\Events\ElementActionsResolving;
use CraftCms\Cms\Element\Events\ElementAttributeHtmlResolving;
use CraftCms\Cms\Element\Events\ElementDeletedForSite;
use CraftCms\Cms\Element\Events\ElementDeleting;
use CraftCms\Cms\Element\Events\ElementRestored;
use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\Element\Events\ElementSaving;
use CraftCms\Cms\Element\Events\ElementDeleted;
use CraftCms\Cms\Element\Queries\Contracts\ElementQueryInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\GarbageCollection\Events\RunningGarbageCollection;
use CraftCms\Cms\Gql\Events\GqlArgumentsResolving;
use CraftCms\Cms\Gql\Types\QueryArgument;
use CraftCms\Cms\Plugin\Events\PluginDisabled;
use CraftCms\Cms\Plugin\Plugin;
use CraftCms\Cms\Plugin\PluginSettings;
use CraftCms\Cms\ProjectConfig\Events\ItemAdded;
use CraftCms\Cms\ProjectConfig\Events\ItemRemoved;
use CraftCms\Cms\ProjectConfig\Events\ItemUpdated;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\TemplateHooks;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Support\Url;
use CraftCms\Cms\Twig\Variables\CraftVariable;
use CraftCms\Cms\User\Data\Permission;
use CraftCms\Cms\User\Elements\User;
use CraftCms\Cms\User\Events\GroupsAndPermissionsAssigned;
use CraftCms\Cms\User\Events\UserAssignedToGroups;
use CraftCms\Cms\User\Events\UserGroupPermissionsSaved;
use CraftCms\Cms\User\Events\UserPermissionsSaved;
use GraphQL\Type\Definition\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Internetztube\ElementRelations\Elements\Actions\ReplaceRelations;
use Internetztube\ElementRelations\Elements\Conditions\UsedConditionRule;
use Internetztube\ElementRelations\Elements\Queries\UsageQuery;
use Internetztube\ElementRelations\Elements\Queries\UsesElement;
use Internetztube\ElementRelations\Elements\RestrictedUsage;
use Internetztube\ElementRelations\Elements\Usage;
use Internetztube\ElementRelations\Fields\ElementRelationsField;
use Internetztube\ElementRelations\Jobs\GenerateResaveAllElementRelationsJobsJob;
use Internetztube\ElementRelations\Jobs\PruneJob;
use Internetztube\ElementRelations\Models\CacheRecord;
use Internetztube\ElementRelations\Models\Replacement;
use Internetztube\ElementRelations\Services\ExtractorService;
use Internetztube\ElementRelations\Services\InUseGuard;
use Internetztube\ElementRelations\Services\QueueElementsForRefreshService;
use Internetztube\ElementRelations\Services\ReplaceService;
use Internetztube\ElementRelations\Support\ElementSummary;
use Internetztube\ElementRelations\Utilities\ElementRelationsUtility;
use Override;
use Psr\Log\LoggerInterface;

use function CraftCms\Cms\currentUserElement;
use function CraftCms\Cms\t;

/**
 * Element Relations: where is an element used? Relations are extracted on element save into the cache table (queue
 * jobs) and read back through Models\RelationsModel (Twig, GraphQL, the field).
 *
 * @method Settings getSettings()
 */
class ElementRelations extends Plugin
{
    /** 4.x's schema: 5.0 needs no migration of its own (minVersionRequired makes sure every 4.x one ran). */
    public string $schemaVersion = '1.0.14';
    public string $minVersionRequired = '4.0.2.1';

    public bool $hasCpSettings = true;

    /** The one edition while the `editions` flag is off: Craft's default, what 3.1 and its Plugin Store licenses use. */
    public const EDITION_STANDARD = 'standard';
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    /** Element types that get the "Replace everywhere…" index action (plugin classes may be absent). */
    public const PRO_ELEMENT_TYPES = [
        Asset::class,
        Entry::class,
        'craft\\elements\\Category', // Craft's Yii2 adapter
        'craft\\elements\\Tag',
        'craft\\commerce\\elements\\Product',
    ];

    /** User permissions (admins have them implicitly). */
    public const PERMISSION_REPLACE = 'element-relations-replace';
    public const PERMISSION_ROLLBACK = 'element-relations-rollback';
    public const PERMISSION_IN_USE = 'element-relations-delete-in-use';

    /** Control panel actions that edit, delete or change the status of an element. */
    private const IN_USE_GUARDED_CONTROLLERS = ['elements', 'element-indexes', 'delete-elements', 'assets', 'users'];

    /** @var array<class-string<\CraftCms\Cms\Field\Contracts\FieldInterface>> */
    protected array $fieldTypes = [ElementRelationsField::class];

    /** @var array<class-string<\CraftCms\Cms\Utility\Utility>> */
    protected array $utilities = [ElementRelationsUtility::class];

    /** @var list<class-string<\Illuminate\Console\Command>> */
    protected array $commands = [
        Console\Commands\ResaveRelationsCommand::class,
        Console\Commands\PruneCommand::class,
        Console\Commands\ReplaceCommand::class,
        Console\Commands\ReplaceMapCommand::class,
        Console\Commands\RollbackCommand::class,
        Console\Commands\ResumeCommand::class,
        Console\Commands\HistoryCommand::class,
    ];

    /**
     * Feature flags from `config/craft/element-relations.php` (`'features' => ['editions' => true]`).
     * @return array<string, mixed>
     */
    public static function features(): array
    {
        $features = config('craft.element-relations.features', []);
        return is_array($features) ? $features : [];
    }

    /**
     * One edition (Craft's `standard`, so existing licenses stay valid) until the `editions` feature flag is on.
     * @return list<string>
     */
    #[Override]
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
        return !empty($features['editions']) ? [self::EDITION_LITE, self::EDITION_PRO] : [self::EDITION_STANDARD];
    }

    /** Whether everything is on: always with the one edition; with the `editions` flag on, in the Pro edition. */
    public static function isPro(): bool
    {
        return self::isProFor(self::features());
    }

    /** @param array<string, mixed> $features */
    public static function isProFor(array $features): bool
    {
        return !in_array(self::EDITION_PRO, self::editionsFor($features), true) || self::instance()?->is(self::EDITION_PRO) === true;
    }

    /** The booted plugin, null when it is not installed (and so not booted). */
    public static function instance(): ?self
    {
        return app()->bound(self::class) ? app(self::class) : null;
    }

    /** Allowed to replace references (console runs without a user are trusted). */
    public static function canReplace(?User $user = null): bool
    {
        return self::isPro() && self::allowed(self::PERMISSION_REPLACE, $user);
    }

    /** Allowed to roll back replacements. */
    public static function canRollBack(?User $user = null): bool
    {
        return self::isPro() && self::allowed(self::PERMISSION_ROLLBACK, $user);
    }

    /** Roll back this operation: own replacements, admins all of them. */
    public static function canRollBackOperation(Replacement $operation, ?User $user = null): bool
    {
        $user ??= currentUserElement();
        if (!self::canRollBack($user)) {
            return false;
        }
        return !$user || $user->admin || (int)$operation->userId === (int)$user->id;
    }

    /**
     * Whether elements in use are protected in this request (Services\InUseGuard): the control panel's element
     * actions, for users without the "Delete or disable elements in use" permission. Console, queue jobs,
     * migrations, settings changes and site requests never are.
     */
    public static function guardsInUse(?User $user = null): bool
    {
        if (!self::protectsInUse() || app()->runningInConsole() || !request()->isCpRequest()) {
            return false;
        }
        if (!in_array(request()->actionSegments()[0] ?? null, self::IN_USE_GUARDED_CONTROLLERS, true)) {
            return false;
        }
        return !self::mayDeleteInUse($user);
    }

    /** Whether the user may delete or disable elements in use (admins may; Lite has no such limit). */
    public static function mayDeleteInUse(?User $user = null): bool
    {
        if (!self::protectsInUse()) {
            return true;
        }
        $user ??= currentUserElement();
        return $user !== null && ($user->admin || $user->can(self::PERMISSION_IN_USE));
    }

    private static function allowed(string $permission, ?User $user): bool
    {
        $user ??= currentUserElement();
        if (!$user) {
            return app()->runningInConsole();
        }
        return $user->admin || $user->can($permission);
    }

    /** With the "Protect elements in use" setting on (the default). */
    public static function protectsInUse(): bool
    {
        return self::isPro() && self::instance()?->getSettings()->protectInUse === true;
    }

    /**
     * Whether to point out Pro: Pro can be bought (editions on), this install is Lite, and the control panel user is an
     * admin (who decides on plugins; editors never see it).
     */
    public static function upsells(): bool
    {
        return in_array(self::EDITION_PRO, self::editions(), true) && !self::isPro()
            && request()->isCpRequest() && currentUserElement()?->admin === true;
    }

    /** The plugin's page in the control panel's Plugin Store, where Pro is bought. */
    public static function upgradeUrl(): string
    {
        return Url::cpUrl('plugin-store/element-relations');
    }

    /**
     * `$type::find()` for loading elements of any type by id. Some plugins limit their element queries to what the
     * control panel user may access (Formie's forms); the plugin checks permissions itself (UsageQuery, canView).
     * @param class-string<ElementInterface> $type
     */
    public static function find(string $type): ElementQueryInterface
    {
        $query = $type::find();
        if (method_exists($query, 'withoutCpIndexScope')) {
            $query->withoutCpIndexScope();
        }
        return $query;
    }

    /** An element of any type, in a site (`'*'`: any of them), drafts included; null when it is gone. */
    public static function elementById(int $id, int|string|null $siteId = null): ?ElementInterface
    {
        return Elements::getElementById($id, null, $siteId);
    }

    /** @var list<class-string<ElementInterface>>|null */
    private static ?array $registeredTypes = null;

    /**
     * The registered element types: usages of others (a plugin removed or disabled) are listed and counted nowhere.
     * @return list<class-string<ElementInterface>>
     */
    public static function elementTypes(): array
    {
        /** @var list<class-string<ElementInterface>> */
        return self::$registeredTypes ??= array_values(Elements::getAllElementTypes());
    }

    /** A site (front-end) web request, where templates render for visitors. */
    public static function isSiteRequest(): bool
    {
        return !app()->runningInConsole() && !request()->isCpRequest();
    }

    /** The control panel user whose view permissions apply: a non-admin in a control panel request, else null. */
    public static function restrictedViewer(): ?User
    {
        if (app()->runningInConsole() || !request()->isCpRequest()) {
            return null;
        }
        $user = currentUserElement();
        return $user && !$user->admin ? $user : null;
    }

    /** The plugin's mask icon (SVG), for the control panel UI next to Craft's own icons. */
    public static function iconPath(): string
    {
        return dirname(__DIR__) . '/resources/icon-mask.svg';
    }

    /**
     * Craft's element index (the field's list, the wizard) is styled by the classic control panel CSS, which Craft 6
     * pages don't load. In the lowest cascade layer of Craft 6's CSS, so the new control panel keeps its own look
     * wherever it styles something itself. The classic CSS also expects the text direction on `<body>` and a reset
     * for plain buttons (sortable column headers), which the classic pages had.
     */
    public static function registerClassicUi(): void
    {
        $url = \CraftCms\Cms\craftAsset('legacy/cp/dist/css/cp.css');
        \CraftCms\Cms\Support\Facades\HtmlStack::css('@import url(' . json_encode($url) . ') layer(base);', [], 'element-relations-classic-css');
        \CraftCms\Cms\Support\Facades\HtmlStack::css('@layer base { :where(.element-relations, .element-relations-wizard, .element-index-view-menu, .element-relations-menu) button { appearance: none; background: none; border: 0; margin: 0; padding: 0; font: inherit; color: inherit; cursor: pointer; } }', [], 'element-relations-classic-reset');
        \CraftCms\Cms\Support\Facades\HtmlStack::js("document.body.classList.contains('ltr') || document.body.classList.contains('rtl') || document.body.classList.add(document.documentElement.dir === 'rtl' ? 'rtl' : 'ltr');", \CraftCms\Cms\View\Enums\Position::Ready, 'element-relations-classic-dir');
    }

    /** The plugin's own log, storage/logs/element-relations-<date>.log: extractor errors, replacements, rollbacks. */
    public static function log(): LoggerInterface
    {
        return Log::channel('element-relations');
    }

    /** Cache flag: the plugin was disabled, so its first boot after that rebuilds the relations cache. */
    private const REBUILD_FLAG = 'element-relations:rebuild-on-enable';

    /** A new install has no cache yet: build it in the queue. */
    #[Override]
    protected function afterInstall(): void
    {
        dispatch(new GenerateResaveAllElementRelationsJobsJob());
    }

    #[Override]
    protected static function createSettings(): ?PluginSettings
    {
        return new Settings();
    }

    /** @return list<Permission> */
    #[Override]
    protected function getPermissions(): array
    {
        if (!self::isPro()) {
            return [];
        }
        return [
            new Permission(self::PERMISSION_REPLACE, t('permission-replace', category: 'element-relations')),
            new Permission(self::PERMISSION_ROLLBACK, t('permission-rollback', category: 'element-relations')),
            new Permission(self::PERMISSION_IN_USE, t('permission-in-use', category: 'element-relations'), t('permission-in-use-info', category: 'element-relations')),
        ];
    }

    #[Override]
    public function settingsForm(\CraftCms\Cms\Form\FormContext $context = new \CraftCms\Cms\Form\FormContext()): ?\CraftCms\Cms\Form\Form
    {
        return SettingsForm::make($this->getSettings());
    }

    #[Override]
    public function register(): void
    {
        parent::register();
        config(['logging.channels.element-relations' => [
            'driver' => 'daily',
            'path' => storage_path('logs/element-relations.log'),
            'level' => 'info',
            'days' => 14,
            'replace_placeholders' => true,
        ]]);
    }

    public function boot(): void
    {
        // `usesElement` / `unused` on every element query (Twig, PHP, GraphQL)
        UsesElement::register();

        // the field's input is control panel HTML (Craft's element index), rendered by Craft's own HTML component
        app(\CraftCms\Cms\Form\FormControlTypes::class)->register(Form\HtmlControl::class);
        app(\CraftCms\Cms\Plugin\Plugins::class)->addScript('element-relations', \CraftCms\Cms\craftAsset('legacy/cpcompat/dist/legacy-html-control.js'));

        // `craft.usages()` in Twig: Usage::find(), everything that uses an element across all element types
        CraftVariable::macro('usages', function (array $criteria = []): UsageQuery {
            $query = Usage::find();
            \CraftCms\Cms\Support\Typecast::configure($query, $criteria);
            return $query;
        });

        // … and as an argument of Craft's own GraphQL queries (`entries(usesElement: 12)`): the resolvers call the
        // query's `usesElement()`, which the macro takes
        Event::listen(function (GqlArgumentsResolving $event): void {
            $event->arguments['usesElement'] = [
                'name' => 'usesElement',
                'type' => Type::listOf(QueryArgument::getType()),
                'description' => 'Narrows the query results to elements that use the given element(s), as Element Relations finds them (relation and link fields, text with reference tags, user photos, …). Takes an element ID or a list of them.',
            ];
        });

        // columns of the field's Usage index (one list across all element types, see Elements/Usage.php)
        Event::listen(function (ElementAttributeHtmlResolving $event): void {
            $element = $event->element;
            if ($element instanceof RestrictedUsage) {
                $event->html = match ($event->attribute) {
                    Usage::ATTR_TYPE => is_subclass_of($element->realType, ElementInterface::class) ? Html::encode($element->realType::displayName()) : '',
                    Usage::ATTR_SITE => ElementSummary::sitesHtml($element),
                    default => '',
                };
                return;
            }
            $html = match ($event->attribute) {
                Usage::ATTR_TYPE => Html::encode($element::displayName()),
                Usage::ATTR_WHERE => Html::encode(ElementSummary::where($element)),
                Usage::ATTR_SITE => ElementSummary::sitesHtml($element),
                Usage::ATTR_FIELD => ElementSummary::viaHtml($element),
                // a Usage index mixes element types: a column this type doesn't have (Authors on an asset) stays empty
                // instead of Craft reading an unknown property
                default => isset(Usage::$via[(int)$element->id])
                    && !isset($element::tableAttributes()[$event->attribute])
                    && !str_contains($event->attribute, ':')
                    ? '' : null,
            };
            if ($html !== null) {
                $event->html = $html;
            }
        });

        // type dropdown in the toolbar of the field's index (Craft's toolbar template offers this hook, nothing else)
        TemplateHooks::register('cp.elements.toolbar', function (array &$context): string {
            if (($context['elementType'] ?? null) !== Usage::class) {
                return '';
            }
            return implode('', array_map(fn(array $menu): string => \CraftCms\Cms\template('element-relations/_field/filter-menu', [
                'menu' => $menu,
                'id' => 'element-relations-menu-' . mt_rand(),
            ], \CraftCms\Cms\View\TemplateMode::Cp), ElementRelationsField::$toolbarMenus));
        });

        // "Replace everywhere…" on element indexes, a "Used anywhere" rule for element conditions; decided per call,
        // so switching the edition needs no restart
        Event::listen(function (ElementActionsResolving $event): void {
            if (in_array($event->elementType, self::PRO_ELEMENT_TYPES, true) && self::canReplace()) {
                $event->actions[] = ReplaceRelations::class;
            }
        });
        Event::listen(function (ConditionRulesResolving $event): void {
            if ($event->condition instanceof ElementCondition && !$event->condition instanceof \Internetztube\ElementRelations\Elements\Conditions\UsageCondition && self::isPro()) {
                $event->conditionRules[] = UsedConditionRule::class;
            }
        });

        // what a user may view among the usages is cached; forget it whenever that could change: a usage (relating
        // element) or a user saved, deleted or restored, group assignments, a user's or group's permissions, project
        // config (sections, volumes, group permissions); new or changed relations reset it in ExtractorService
        $forgetPermissions = fn() => UsageQuery::resetPermissionCache();
        $forgetIfUsage = function (ElementInterface $element) use ($forgetPermissions): void {
            if ($element instanceof User || DB::table(CacheRecord::TABLE)->where('sourcePrimaryOwnerId', $element->id)->exists()) {
                $forgetPermissions();
            }
        };
        Event::listen(fn(ElementDeleted $event) => $forgetIfUsage($event->element));
        Event::listen(fn(ElementRestored $event) => $forgetIfUsage($event->element));
        foreach ([UserAssignedToGroups::class, UserPermissionsSaved::class, UserGroupPermissionsSaved::class, GroupsAndPermissionsAssigned::class, ItemAdded::class, ItemUpdated::class, ItemRemoved::class] as $event) {
            Event::listen($event, $forgetPermissions);
        }

        // Craft 6 normalizes every field value while saving (setFieldValue), so by ElementSaved each field reads as
        // dirty: what the save really changed is known only before it
        /** @var \WeakMap<ElementInterface, array<string>> $changedFields */
        $changedFields = new \WeakMap();
        Event::listen(function (ElementSaving $event) use ($changedFields): void {
            $changedFields[$event->element] = $event->element->getDirtyFields();
        });
        Event::listen(function (ElementSaved $event) use ($forgetIfUsage, $changedFields): void {
            $element = $event->element;
            $forgetIfUsage($element);
            // Revisions are never shown as relation sources, so don't waste queue time and cache rows on them.
            if ($element->getIsRevision()) {
                return;
            }
            // Only changed custom fields can change field relations (Craft tracks them per save, incl. propagation to
            // other sites). Elements read by an element-level extractor (user photo) have no such signal and always
            // refresh.
            $hasElementExtractor = ExtractorService::hasElementExtractor($element);
            $changed = $changedFields[$element] ?? $element->getDirtyFields();
            unset($changedFields[$element]);
            if (!$event->isNew && !$element->isNewForSite && !$changed && !$hasElementExtractor) {
                return;
            }
            if ($hasElementExtractor) {
                // their data is not per site (a user's photo) and Craft saves only one site
                foreach (ElementHelper::supportedSitesForElement($element) as $site) {
                    QueueElementsForRefreshService::registerForRefresh((int)$element->id, (int)$site['siteId']);
                }
                return;
            }
            QueueElementsForRefreshService::registerForRefresh((int)$element->id, (int)$element->siteId);
        });

        Event::listen(function (ElementDeleting $event): void {
            $element = $event->element;
            // A trashed element can be restored, so its usages stay (lists and counts skip trashed elements, the list's
            // "Trashed" status shows them; garbage collection removes their rows with the elements, below).
            // Not for nested elements: Craft trashes a Matrix block's entry when it is removed from its owner, whose
            // usage it would otherwise still be; restoring the owner refreshes them (below).
            $nested = $element instanceof NestedElementInterface && $element->getPrimaryOwnerId() !== null;
            if ($event->hardDelete || $nested) {
                ExtractorService::forgetElement($element); // every site: a deleted element is gone in all of them
            }
        });
        // Craft's garbage collection hard-deletes trashed elements (nested ones by cascade) in SQL, without element
        // events; its event fires once those deletions are done, so their rows go then (in the queue)
        Event::listen(fn(RunningGarbageCollection $event) => dispatch(new PruneJob()));
        // deleted for one site: that site's rows go, the other sites keep theirs
        Event::listen(fn(ElementDeletedForSite $event) => ExtractorService::forgetElement($event->element, (int)$event->element->siteId));
        Event::listen(function (ElementRestored $event): void {
            $element = $event->element;
            if (!$element instanceof NestedElementInterface || $element->getPrimaryOwnerId() === null) {
                ReplaceService::refreshOwnerTree((int)$element->id); // its nested elements' rows
            }
        });

        // elements in use can't be deleted or taken offline in the control panel without a permission
        InUseGuard::register();

        // while disabled, nothing tracks the saves: the next boot (enabled again) rebuilds the cache. Craft enables a
        // plugin without booting it, so the flag is set while it is still running.
        // ponytail: a cache flag, lost when the cache is cleared while the plugin is off (then rebuild from the utility)
        Event::listen(function (PluginDisabled $event): void {
            if ($event->plugin instanceof self) {
                \Illuminate\Support\Facades\Cache::forever(self::REBUILD_FLAG, true);
            }
        });
        if (\Illuminate\Support\Facades\Cache::pull(self::REBUILD_FLAG)) {
            dispatch(new GenerateResaveAllElementRelationsJobsJob());
        }

        // the elements saved in this request: their refresh jobs go out at its end (HTTP and console), or after each
        // job of a queue worker
        app()->terminating(fn() => QueueElementsForRefreshService::destruct());
        Event::listen(\Illuminate\Queue\Events\JobProcessed::class, fn() => QueueElementsForRefreshService::destruct());
    }
}
