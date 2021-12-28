<?php

namespace internetztube\elementRelations;

use Craft;
use craft\base\Element;
use craft\base\Plugin;
use craft\events\ModelEvent;
use craft\events\PluginEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;
use craft\services\Plugins;
use craft\services\Utilities;
use internetztube\elementRelations\fields\ElementRelationsField;
use internetztube\elementRelations\jobs\CreateRefreshElementRelationsJobsJob;
use internetztube\elementRelations\jobs\RefreshRelatedElementRelationsJob;
use internetztube\elementRelations\models\Settings;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\utilities\ElementRelationsUtility;
use yii\base\Event;

class ElementRelations extends Plugin
{
    public static $plugin;
    public $schemaVersion = '1.0.1';
    public $hasCpSettings = false;
    public $hasCpSection = false;

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

        /**
         * Push RefreshRelatedElementRelationsJobs to Queue, when a Element got propagated to a site. This Job only
         * gets pushed to the Queue once.
         */
        $pushedQueueTasks = [];
        Event::on(Element::class, Element::EVENT_AFTER_PROPAGATE, function (ModelEvent $event) use (&$pushedQueueTasks) {
            /** @var Element $element */
            $element = $event->sender;
            $needle = sprintf('%s-%s', $element->canonicalId, $element->siteId);
            if (in_array($needle, $pushedQueueTasks)) {
                return;
            }
            $pushedQueueTasks[] = $needle;
            $job = new RefreshRelatedElementRelationsJob(['elementId' => $element->canonicalId, 'siteId' => $element->siteId,]);
            Craft::$app->getQueue()->push($job);
        });

        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITY_TYPES, function (RegisterComponentTypesEvent $event) {
            $event->types[] = ElementRelationsUtility::class;
        });

        // Enqueue Job that creates caches for all Elements with "Element Relations"-Field when Plugin gets enabled.
        $pluginEnableCallback = function (PluginEvent $event) {
            // cache check if happening in the queue task
            if (!($event->plugin instanceof ElementRelations)) { return; }
            if (!CacheService::useCache()) { return; }
            $job = new CreateRefreshElementRelationsJobsJob(['force' => true]);
            // delay job by 1min
            Craft::$app->getQueue()->delay(1 * 60)->push($job);
        };
        Event::on(Plugins::class, Plugins::EVENT_AFTER_ENABLE_PLUGIN, $pluginEnableCallback);
        Event::on(Plugins::class, Plugins::EVENT_AFTER_INSTALL_PLUGIN, $pluginEnableCallback);
    }

    protected function createSettingsModel()
    {
        return new Settings();
    }
}
