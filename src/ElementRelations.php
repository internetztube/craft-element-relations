<?php

namespace internetztube\elementRelations;

use craft\base\Element;
use craft\db\Query;
use craft\db\Table;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use internetztube\elementRelations\fields\ElementRelationsField;
use internetztube\elementRelations\services\ElementRelationsService;

use Craft;
use craft\base\Plugin;
use craft\services\Plugins;
use craft\events\PluginEvent;
use craft\services\Fields;
use craft\events\RegisterComponentTypesEvent;

use yii\base\Event;

class ElementRelations extends Plugin
{
    public static $plugin;
    public $schemaVersion = '1.0.0';
    public $hasCpSettings = false;
    public $hasCpSection = false;

    public function init()
    {
        parent::init();
        self::$plugin = $this;

        // Register services as components
        $this->setComponents([
            'elementRelations' => ElementRelationsService::class,
        ]);

        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function (RegisterComponentTypesEvent $event) {
            $event->types[] = ElementRelationsField::class;
        });
    }
}
