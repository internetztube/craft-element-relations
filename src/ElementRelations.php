<?php

namespace internetztube\elementRelations;

use craft\base\Element;
use craft\db\Table;
use internetztube\elementRelations\models\Settings;
use internetztube\elementRelations\fields\Relations as RelationsField;

use Craft;
use craft\base\Plugin;
use craft\services\Plugins;
use craft\events\PluginEvent;
use craft\services\Fields;
use craft\events\RegisterComponentTypesEvent;

use yii\base\BaseObject;
use yii\base\Event;


class ElementRelations extends Plugin
{
    public static $plugin;
    public $schemaVersion = '1.0.0';
    public $hasCpSettings = true;
    public $hasCpSection = false;

    public function init()
    {
        parent::init();
        self::$plugin = $this;

        // Register our fields
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = RelationsField::class;
            }
        );

        // Do something after we're installed
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent $event) {
                if ($event->plugin === $this) {
                    // We were just installed
                }
            }
        );

        Event::on(Plugins::class, Plugins::EVENT_AFTER_LOAD_PLUGINS, function (Event $event) {
            $getElementById = function (int $elementId) {
                $result = (new Query())->select(['type'])->from(Table::ELEMENTS)->where(['id' => $elementId])->one();
                if (!$result) { return null; }
                return $result['type']::find()->id($elementId)->anyStatus()->site('*')->one();
            };
            $getRootElement = function (Element $element) use ($getElementById, &$getRootElement) {
                if (!isset($element->ownerId) || !$element->ownerId) { return $element; }
                $sourceElement = $getElementById($element->ownerId);
                if (!$sourceElement) { return null; } // relations are broken
                return $getRootElement($sourceElement);
            };


            $sourceId = 658113;
            $sourceElement = $getElementById($sourceId);

            $results = (new Query())
                ->select(['elements.id'])
                ->from(['relations' => Table::RELATIONS])
                ->innerJoin(['elements' => Table::ELEMENTS], '[[relations.sourceId]] = [[elements.id]]')
                ->where(['relations.targetId' => $sourceId])
                ->column();

            $results = collect($results)->map(function(int $elementId) use ($getRootElement, $getElementById) {
                $element = $getElementById($elementId);
                return $getRootElement($element);
            })->filter()->map(function (Element $element) use ($sourceElement) {
                return sprintf('%s#:~:text=%s', $element->getCpEditUrl(), $sourceElement->title);
            });
            dd($results);
        });
        return;
    }

    protected function createSettingsModel()
    {
        return new Settings();
    }

    protected function settingsHtml()
    {
        return Craft::$app->view->renderTemplate(
            'element-relations/settings',
            [
                'settings' => $this->getSettings()
            ]
        );
    }
}
