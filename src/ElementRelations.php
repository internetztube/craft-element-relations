<?php

namespace internetztube\elementRelations;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\events\ModelEvent;
use craft\events\PluginEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Plugins;
use craft\services\Utilities;
use internetztube\elementRelations\assetbundles\ElementRelationsAsset;
use internetztube\elementRelations\fields\ElementRelationsField;
use internetztube\elementRelations\jobs\GenerateResaveAllElementRelationsJobsJob;
use internetztube\elementRelations\models\Settings;
use internetztube\elementRelations\models\SettingsModel;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\services\ExtractorService;
use internetztube\elementRelations\services\ProfilePhotoService;
use internetztube\elementRelations\services\SeomaticService;
use internetztube\elementRelations\services\UserPhotoService;
use internetztube\elementRelations\twigextensions\ControlPanel;
use internetztube\elementRelations\twigextensions\Main;
use internetztube\elementRelations\utilities\ElementRelationsUtility;
use internetztube\elementRelations\services\QueueElementsForRefreshService;
use yii\base\Application;
use yii\base\Event;

class ElementRelations extends Plugin
{
    public static ElementRelations $plugin;
    public string $schemaVersion = "1.0.7";
    public bool $hasCpSettings = true;
    public bool $hasCpSection = false;

    public function init()
    {
        parent::init();
        self::$plugin = $this;

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'internetztube\elementRelations\console\controllers';
        }

        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function (RegisterComponentTypesEvent $event) {
            $event->types[] = ElementRelationsField::class;
        });

        if (version_compare(Craft::$app->getVersion(), "5.0", ">=")) {
            $utilitiesEvent = Utilities::EVENT_REGISTER_UTILITIES;
        } else {
            $utilitiesEvent = Utilities::EVENT_REGISTER_UTILITY_TYPES;
        }
        Event::on(Utilities::class, $utilitiesEvent, function (RegisterComponentTypesEvent $event) {
            $event->types[] = ElementRelationsUtility::class;
        });

        Event::on(Element::class, Element::EVENT_AFTER_SAVE, function (ModelEvent $event) {
            /** @var Element $element */
            $element = $event->sender;
            QueueElementsForRefreshService::registerForRefresh($element->id, $element->siteId);
        });

        Event::on(Element::class, Element::EVENT_BEFORE_DELETE, function(ModelEvent $event) {
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

        Event::on(Application::class, Application::EVENT_AFTER_REQUEST, function() {
            QueueElementsForRefreshService::destruct();
        });
        
        if (Craft::$app->getRequest()->getIsCpRequest()) {
            Craft::$app->getView()->registerAssetBundle(ElementRelationsAsset::class);
            Craft::$app->view->registerTwigExtension(new ControlPanel());
        }
    }

    protected function settingsHtml(): ?string
    {
        return \Craft::$app->getView()->renderTemplate(
            'element-relations/settings',
            ['settings' => $this->getSettings()]
        );
    }


    protected function createSettingsModel(): ?Model
    {
        return new SettingsModel();
    }
}
