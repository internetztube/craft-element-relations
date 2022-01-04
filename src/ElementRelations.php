<?php

namespace internetztube\elementRelations;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\User;
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
use internetztube\elementRelations\services\ElementRelationsService;
use internetztube\elementRelations\utilities\ElementRelationsUtility;
use nystudio107\seomatic\services\MetaContainers;
use yii\base\Event;

class ElementRelations extends Plugin
{
    public static $plugin;
    public $schemaVersion = '1.0.1';
    public $hasCpSettings = false;
    public $hasCpSection = false;

    private $pushedQueueTasks = [];

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

        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITY_TYPES, function (RegisterComponentTypesEvent $event) {
            $event->types[] = ElementRelationsUtility::class;
        });

        $this->registerUserEvents();
        $this->registerElementEvents();
        $this->registerPluginEvents();

        if (ElementRelationsService::isSeomaticEnabled()) {
            $this->registerSeomaticEvents();
        }
    }

    /**
     * Register all User related Events.
     */
    private function registerUserEvents(): void
    {
        Event::on(User::class, User::EVENT_AFTER_SAVE, function (ModelEvent $event) {
            /** @var User $user */
            $user = $event->sender;
            $job = new RefreshRelatedElementRelationsJob(['identifier' => $user->id]);
            Craft::$app->getQueue()->push($job);

            if ($user->photoId) {
                $job = new RefreshElementRelationsJob(['elementIds' => [$user->photoId], 'force' => true]);
                Craft::$app->getQueue()->push($job);
            }
        });
    }

    /**
     * Register all Element related Events.
     */
    private function registerElementEvents(): void
    {
        /**
         * Push RefreshRelatedElementRelationsJob to Queue, when a Element got propagated to a site. This Job only
         * gets pushed to the Queue once.
         */
        Event::on(Element::class, Element::EVENT_AFTER_SAVE, function (ModelEvent $event) {
            /** @var Element $element */
            $element = $event->sender->canonical;
            // There is an extra event for user photos
            if (in_array($element->id, $this->pushedQueueTasks) || $element instanceof User) {
                return;
            }
            $this->pushedQueueTasks[] = $element->id;
            $job = new RefreshRelatedElementRelationsJob(['identifier' => $element->id]);
            Craft::$app->getQueue()->push($job);
        });
    }

    /**
     * Register all Plugin related Events.
     */
    private function registerPluginEvents(): void
    {
        // Enqueue Job that creates caches for all Elements with "Element Relations"-Field when Plugin gets enabled.
        $pluginEnableCallback = function (PluginEvent $event) {
            // cache check is happening in the queue task
            if (!($event->plugin instanceof ElementRelations)) {
                return;
            }
            $job = new CreateRefreshElementRelationsJobsJob(['force' => true]);
            Craft::$app->getQueue()->delay(1 * 60)->push($job); // delay job by 1min
        };
        Event::on(Plugins::class, Plugins::EVENT_AFTER_ENABLE_PLUGIN, $pluginEnableCallback);
        Event::on(Plugins::class, Plugins::EVENT_AFTER_INSTALL_PLUGIN, $pluginEnableCallback);
    }

    /**
     * Register all SEOmatic related Events.
     */
    private function registerSeomaticEvents(): void
    {
        Event::on(
            MetaContainers::class,
            MetaContainers::EVENT_INVALIDATE_CONTAINER_CACHES,
            function (MetaContainers $event) {
                if ($event->uri) {
                    return;
                }
                $elementIds = collect(ElementRelationsService::getGlobalSeomaticAssets())
                    ->pluck('elementId')->unique();
                $job = new RefreshElementRelationsJob(['elementIds' => $elementIds, 'force' => true]);
                Craft::$app->getQueue()->push($job);

                $job = new RefreshRelatedElementRelationsJob([
                    'identifier' => ElementRelationsService::IDENTIFIER_SEOMATIC_GLOBAL
                ]);
                Craft::$app->getQueue()->push($job);
            });
    }

    /**
     * Register Settings Model.
     * @return Model
     */
    protected function createSettingsModel(): Model
    {
        return new Settings();
    }
}
