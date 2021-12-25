<?php

namespace internetztube\elementRelations;

use craft\base\Element;
use internetztube\elementRelations\fields\ElementRelationsField;
use internetztube\elementRelations\models\Settings;
use internetztube\elementRelations\services\ElementRelationsService;

use Craft;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;
use yii\base\Event;

/**
 *
 * @method   Settings  getSettings()
 */
class ElementRelations extends Plugin
{
    public static $plugin;
    public        $schemaVersion = '1.0.1';
    public        $hasCpSettings = false;
    public        $hasCpSection  = false;

    public function init()
    {
        parent::init();
        self::$plugin = $this;

        // Add in our console commands
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'internetztube\elementrelations\console\controllers';
        }

        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = ElementRelationsField::class;
            }
        );

        /**
         * When an entry is updated, clear any cached records
         */
        Event::on(
            Entry::class,
            Element::EVENT_AFTER_PROPAGATE,
            function (ModelEvent $event) {
                /* @var $entry Entry */
                $entry = $event->sender;
                ElementRelationsService::refreshEntryRelations($entry);
            }
        );
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function createSettingsModel()
    {
        return new Settings();
    }
}
