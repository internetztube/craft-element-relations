<?php

namespace internetztube\elementRelations;

use Craft;
use craft\base\Element;
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
use craft\events\RegisterConditionRulesEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterElementSourcesEvent;
use craft\services\Fields;
use craft\services\Plugins;
use craft\services\Utilities;
use craft\helpers\Html;
use internetztube\elementRelations\behaviors\ElementQueryBehavior;
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
    public string $schemaVersion = "1.0.8";

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
    /** Element types whose index gets the Pro "Unused" source (elements that exist to be related to). */
    public const PRO_UNUSED_TYPES = [
        \craft\elements\Asset::class,
        \craft\elements\Category::class,
        \craft\elements\Tag::class,
    ];

    /**
     * Feature flags from `config/element-relations.php` (`'features' => ['editions' => true]`). Read from the file, not
     * the settings model, because Craft asks for editions() before the plugin instance exists.
     */
    public static function features(): array
    {
        return Craft::$app->getConfig()->getConfigFromFile('element-relations')['features'] ?? [];
    }

    /** Lite only until the `editions` feature flag is on; Pro has no features yet, this is the scaffolding. */
    public static function editions(): array
    {
        return self::editionsFor(self::features());
    }

    public static function editionsFor(array $features): array
    {
        return !empty($features['editions']) ? [self::EDITION_LITE, self::EDITION_PRO] : [self::EDITION_LITE];
    }

    /** Gate for Pro-only behaviour: the flag must be on and the installed edition must be Pro. */
    public static function isPro(): bool
    {
        return in_array(self::EDITION_PRO, self::editions(), true) && self::getInstance()->is(self::EDITION_PRO);
    }
    public bool $hasCpSettings = true;
    public bool $hasCpSection = false;

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'internetztube\elementRelations\console\controllers';
        }

        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function (RegisterComponentTypesEvent $event) {
            $event->types[] = ElementRelationsField::class;
        });

        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, function (RegisterComponentTypesEvent $event) {
            $event->types[] = ElementRelationsUtility::class;
        });

        // `usesElement` element query param; a fixed name so Craft's ClonefixTrait keeps it on cloned queries
        Event::on(ElementQuery::class, Query::EVENT_DEFINE_BEHAVIORS, function (DefineBehaviorsEvent $event) {
            $event->behaviors['elementRelations'] = ElementQueryBehavior::class;
        });

        // columns of the field's Usage index (one list across all element types, see elements/Usage.php)
        Event::on(Element::class, Element::EVENT_DEFINE_ATTRIBUTE_HTML, function (DefineAttributeHtmlEvent $event) {
            /** @var Element $element */
            $element = $event->sender;
            $event->html = match ($event->attribute) {
                Usage::ATTR_TYPE => Html::encode($element::displayName()),
                Usage::ATTR_WHERE => Html::encode(ElementSummary::where($element)),
                Usage::ATTR_SITE => Html::encode(Craft::t('site', $element->getSite()->name)),
                Usage::ATTR_FIELD => ElementSummary::viaHtml($element),
                // a Usage index mixes element types: a column this type doesn't have (Authors on an asset) stays empty
                // instead of Craft reading an unknown property
                default => isset(Usage::$via["$element->id:$element->siteId"])
                    && !isset($element::tableAttributes()[$event->attribute])
                    && !str_contains($event->attribute, ':')
                    ? '' : $event->html,
            };
        });


        // type dropdown in the toolbar of the field's index (Craft's toolbar template offers this hook, nothing else)
        Craft::$app->getView()->hook('cp.elements.toolbar', function (array &$context): string {
            if (($context['elementType'] ?? null) !== Usage::class) {
                return '';
            }
            $html = '';
            if (ElementRelationsField::$toolbarTypes) {
                $html .= Craft::$app->getView()->renderTemplate('element-relations/_field/type-menu', [
                    'types' => ElementRelationsField::$toolbarTypes,
                    'id' => 'element-relations-types-' . mt_rand(),
                ]);
            }
            if (ElementRelationsField::$replaceSettings) { // Pro: replace wizard
                $html .= Craft::$app->getView()->renderTemplate('element-relations/_field/replace', [
                    'settings' => ElementRelationsField::$replaceSettings,
                    'id' => 'element-relations-replace-' . mt_rand(),
                ]);
            }
            return $html;
        });

        // Pro: a "Used anywhere" condition rule (filter HUD, custom sources), an "Unused" source on asset/category/tag
        // indexes and a "Replace everywhere…" action on indexes.
        // Registered unconditionally and decided per call, so switching the edition needs no restart.
        foreach (self::PRO_ELEMENT_TYPES as $class) {
            if (!class_exists($class)) {
                continue;
            }
            Event::on($class, Element::EVENT_REGISTER_ACTIONS, function (RegisterElementActionsEvent $event) {
                if (self::isPro()) {
                    $event->actions[] = ReplaceRelations::class;
                }
            });
        }
        Event::on(ElementCondition::class, BaseCondition::EVENT_REGISTER_CONDITION_RULES, function (RegisterConditionRulesEvent $event) {
            if (self::isPro()) {
                $event->conditionRules[] = UsedConditionRule::class;
            }
        });
        foreach (self::PRO_UNUSED_TYPES as $class) {
            Event::on($class, Element::EVENT_REGISTER_SOURCES, function (RegisterElementSourcesEvent $event) {
                if ($event->context === 'index' && self::isPro()) {
                    $event->sources[] = ['heading' => $this->name];
                    $event->sources[] = [
                        'key' => 'elementRelations:unused',
                        'label' => Craft::t('element-relations', 'source-unused'),
                        'criteria' => ['unused' => true],
                        'defaultSort' => ['dateUpdated', 'desc'],
                    ];
                }
            });
        }

        // Non-element usages: rewrite their cache rows whenever the plugin settings they live in change
        if (class_exists(\nystudio107\seomatic\records\MetaBundle::class)) {
            foreach ([ActiveRecord::EVENT_AFTER_INSERT, ActiveRecord::EVENT_AFTER_UPDATE, ActiveRecord::EVENT_AFTER_DELETE] as $eventName) {
                Event::on(\nystudio107\seomatic\records\MetaBundle::class, $eventName, fn() => GlobalUsageSeomaticService::refresh());
            }
        }

        Event::on(Element::class, Element::EVENT_AFTER_SAVE, function (ModelEvent $event) {
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
                    QueueElementsForRefreshService::registerForRefresh($element->id, (int)$site['siteId']);
                }
                return;
            }
            QueueElementsForRefreshService::registerForRefresh($element->id, $element->siteId);
        });

        Event::on(Element::class, Element::EVENT_BEFORE_DELETE, function (ModelEvent $event) {
            /** @var Element $element */
            $element = $event->sender;
            ExtractorService::deleteRelationsForElement($element);
        });

        $pluginEnableCallback = function (PluginEvent $event) {
            if (!($event->plugin instanceof ElementRelations)) {
                return;
            }
            Craft::$app->getQueue()->priority(1021)->push(new GenerateResaveAllElementRelationsJobsJob());
        };
        Event::on(Plugins::class, Plugins::EVENT_AFTER_ENABLE_PLUGIN, $pluginEnableCallback);
        Event::on(Plugins::class, Plugins::EVENT_AFTER_INSTALL_PLUGIN, $pluginEnableCallback);

        Event::on(Application::class, Application::EVENT_AFTER_REQUEST, function () {
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
