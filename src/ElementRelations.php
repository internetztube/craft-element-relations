<?php

namespace internetztube\elementRelations;

use Craft;
use craft\base\Element;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\events\PluginEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;
use craft\services\Plugins;
use craft\services\Utilities;
use internetztube\elementRelations\fields\ElementRelationsField;
use internetztube\elementRelations\jobs\ResaveAllElementRelationsJob;
use internetztube\elementRelations\jobs\ResaveSingleElementRelations;
use internetztube\elementRelations\models\Settings;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\services\ProfilePhotoService;
use internetztube\elementRelations\services\SeomaticService;
use internetztube\elementRelations\services\UserPhotoService;
use internetztube\elementRelations\twigextensions\ControlPanel;
use internetztube\elementRelations\utilities\ElementRelationsUtility;
use yii\base\Event;

class ElementRelations extends Plugin
{
    public static ElementRelations $plugin;
    public string $schemaVersion = "1.0.6";
    public bool $hasCpSettings = false;
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

        Event::on(Element::class, Element::EVENT_AFTER_SAVE, function (Event $event) {
            /** @var Element $element */
            $element = $event->sender;
            $job = new ResaveSingleElementRelations([
                'elementId' => $element->id,
                'siteId' => $element->siteId,
            ]);
            Craft::$app->getQueue()->priority(1022)->push($job);
        });

        $pluginEnableCallback = function (PluginEvent $event) {
            if (!($event->plugin instanceof ElementRelations)) {
                return;
            }
            Craft::$app->getQueue()->priority(1021)->push(new ResaveAllElementRelationsJob);
        };
        Event::on(Plugins::class, Plugins::EVENT_AFTER_ENABLE_PLUGIN, $pluginEnableCallback);
        Event::on(Plugins::class, Plugins::EVENT_AFTER_INSTALL_PLUGIN, $pluginEnableCallback);

        Craft::$app->view->registerTwigExtension(new ControlPanel());
    }
}
