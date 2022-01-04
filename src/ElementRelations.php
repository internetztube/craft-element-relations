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
use internetztube\elementRelations\jobs\RefreshElementRelationsJob;
use internetztube\elementRelations\jobs\RefreshRelatedElementRelationsJob;
use internetztube\elementRelations\models\Settings;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\services\ElementRelationsService;
use internetztube\elementRelations\utilities\ElementRelationsUtility;
use nystudio107\seomatic\base\MetaContainer;
use nystudio107\seomatic\events\InvalidateContainerCachesEvent;
use nystudio107\seomatic\Seomatic;
use nystudio107\seomatic\services\MetaContainers;
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
        Event::on(Element::class, Element::EVENT_AFTER_SAVE, function (ModelEvent $event) use (&$pushedQueueTasks) {
            /** @var Element $element */
            $element = $event->sender->canonical;
            if (in_array($element->id, $pushedQueueTasks)) {
                return;
            }
            $pushedQueueTasks[] = $element->id;
            $job = new RefreshRelatedElementRelationsJob(['elementId' => $element->id]);
            Craft::$app->getQueue()->push($job);
        });

        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITY_TYPES, function (RegisterComponentTypesEvent $event) {
            $event->types[] = ElementRelationsUtility::class;
        });

        // Enqueue Job that creates caches for all Elements with "Element Relations"-Field when Plugin gets enabled.
        $pluginEnableCallback = function (PluginEvent $event) {
            // cache check if happening in the queue task
            if (!($event->plugin instanceof ElementRelations)) {
                return;
            }
            if (!CacheService::useCache()) {
                return;
            }
            $job = new CreateRefreshElementRelationsJobsJob(['force' => true]);
            // delay job by 1min
            Craft::$app->getQueue()->delay(1 * 60)->push($job);
        };
        Event::on(Plugins::class, Plugins::EVENT_AFTER_ENABLE_PLUGIN, $pluginEnableCallback);
        Event::on(Plugins::class, Plugins::EVENT_AFTER_INSTALL_PLUGIN, $pluginEnableCallback);

        Event::on(MetaContainers::class, MetaContainers::EVENT_INVALIDATE_CONTAINER_CACHES, function (InvalidateContainerCachesEvent $event) {
            if ($event->uri) {
                return;
            }

            $elementIds = collect(ElementRelationsService::getGlobalSeomaticAssets())->pluck('elementId')->unique();
            $job = new RefreshElementRelationsJob(['elements' => $elementIds, 'force' => true]);
            Craft::$app->getQueue()->push($job);

            $job = new RefreshRelatedElementRelationsJob(['identifier' => ElementRelationsService::IDENTIFIER_SEOMATIC_GLOBAL]);
            Craft::$app->getQueue()->push($job);
        });
    }

    protected function createSettingsModel()
    {
        return new Settings();
    }
}
