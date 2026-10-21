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
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;
use craft\services\Plugins;
use craft\services\Utilities;
use craft\helpers\Html;
use internetztube\elementRelations\behaviors\ElementQueryBehavior;
use internetztube\elementRelations\elements\Usage;
use internetztube\elementRelations\helpers\ElementSummary;
use internetztube\elementRelations\fields\ElementRelationsField;
use internetztube\elementRelations\jobs\GenerateResaveAllElementRelationsJobsJob;
use internetztube\elementRelations\models\SettingsModel;
use internetztube\elementRelations\services\ExtractorService;
use internetztube\elementRelations\services\QueueElementsForRefreshService;
use internetztube\elementRelations\utilities\ElementRelationsUtility;
use yii\base\Application;
use yii\base\Event;

/**
 * @method SettingsModel getSettings()
 */
class ElementRelations extends Plugin
{
    public static ElementRelations $plugin;
    public string $schemaVersion = "1.0.7";
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
            if (($context['elementType'] ?? null) !== Usage::class || !ElementRelationsField::$toolbarTypes) {
                return '';
            }
            return Craft::$app->getView()->renderTemplate('element-relations/_field/type-menu', [
                'types' => ElementRelationsField::$toolbarTypes,
                'id' => 'element-relations-types-' . mt_rand(),
            ]);
        });

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
