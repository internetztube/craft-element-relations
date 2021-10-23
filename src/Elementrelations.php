<?php
/**
 * element-relations plugin for Craft CMS 3.x
 *
 * Indicates where a certain element is used.
 *
 * @link      https://frederickoeberl.com/
 * @copyright Copyright (c) 2021 Frederic Koeberl
 */

namespace internetztube\elementrelations;

use craft\base\Element;
use craft\db\Table;
use internetztube\elementrelations\models\Settings;
use internetztube\elementrelations\fields\Relations as RelationsField;

use Craft;
use craft\base\Plugin;
use craft\services\Plugins;
use craft\events\PluginEvent;
use craft\services\Fields;
use craft\events\RegisterComponentTypesEvent;

use yii\base\BaseObject;
use yii\base\Event;


/**
 * Craft plugins are very much like little applications in and of themselves. We’ve made
 * it as simple as we can, but the training wheels are off. A little prior knowledge is
 * going to be required to write a plugin.
 *
 * For the purposes of the plugin docs, we’re going to assume that you know PHP and SQL,
 * as well as some semi-advanced concepts like object-oriented programming and PHP namespaces.
 *
 * https://docs.craftcms.com/v3/extend/
 *
 * @author    Frederic Koeberl
 * @package   Elementrelations
 * @since     1.0.0
 *
 * @property  Settings $settings
 * @method    Settings getSettings()
 */
class Elementrelations extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * Static property that is an instance of this plugin class so that it can be accessed via
     * Elementrelations::$plugin
     *
     * @var Elementrelations
     */
    public static $plugin;

    // Public Properties
    // =========================================================================

    /**
     * To execute your plugin’s migrations, you’ll need to increase its schema version.
     *
     * @var string
     */
    public $schemaVersion = '1.0.0';

    /**
     * Set to `true` if the plugin should have a settings view in the control panel.
     *
     * @var bool
     */
    public $hasCpSettings = true;

    /**
     * Set to `true` if the plugin should have its own section (main nav item) in the control panel.
     *
     * @var bool
     */
    public $hasCpSection = false;

    // Public Methods
    // =========================================================================

    /**
     * Set our $plugin static property to this class so that it can be accessed via
     * Elementrelations::$plugin
     *
     * Called after the plugin class is instantiated; do any one-time initialization
     * here such as hooks and events.
     *
     * If you have a '/vendor/autoload.php' file, it will be loaded for you automatically;
     * you do not need to load it in your init() method.
     *
     */
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

/**
 * Logging in Craft involves using one of the following methods:
 *
 * Craft::trace(): record a message to trace how a piece of code runs. This is mainly for development use.
 * Craft::info(): record a message that conveys some useful information.
 * Craft::warning(): record a warning message that indicates something unexpected has happened.
 * Craft::error(): record a fatal error that should be investigated as soon as possible.
 *
 * Unless `devMode` is on, only Craft::warning() & Craft::error() will log to `craft/storage/logs/web.log`
 *
 * It's recommended that you pass in the magic constant `__METHOD__` as the second parameter, which sets
 * the category to the method (prefixed with the fully qualified class name) where the constant appears.
 *
 * To enable the Yii debug toolbar, go to your user account in the AdminCP and check the
 * [] Show the debug toolbar on the front end & [] Show the debug toolbar on the Control Panel
 *
 * http://www.yiiframework.com/doc-2.0/guide-runtime-logging.html
 */
        Craft::info(
            Craft::t(
                'element-relations',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }

    // Protected Methods
    // =========================================================================

    /**
     * Creates and returns the model used to store the plugin’s settings.
     *
     * @return \craft\base\Model|null
     */
    protected function createSettingsModel()
    {
        return new Settings();
    }

    /**
     * Returns the rendered settings HTML, which will be inserted into the content
     * block on the settings page.
     *
     * @return string The rendered settings HTML
     */
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
