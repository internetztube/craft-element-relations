<?php

namespace internetztube\elementRelations;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;
use craft\services\Utilities;
use internetztube\elementRelations\fields\ElementRelationsField;
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
    public string $schemaVersion = '1.0.5';
    public bool $hasCpSettings = false;
    public bool $hasCpSection = false;

    public function init()
    {
        parent::init();
        self::$plugin = $this;

        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function (RegisterComponentTypesEvent $event) {
            $event->types[] = ElementRelationsField::class;
        });

        Craft::$app->view->registerTwigExtension(new ControlPanel());
    }
}
