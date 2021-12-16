<?php

namespace internetztube\elementRelations\console\controllers;

use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Volume;
use craft\elements\Asset;
use craft\errors\SiteNotFoundException;
use craft\helpers\Cp;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use internetztube\elementRelations\ElementRelations;

use Craft;
use internetztube\elementRelations\fields\ElementRelationsField;
use internetztube\elementRelations\services\ElementRelationsService;
use nystudio107\imageoptimize\fields\OptimizedImages as OptimizedImagesField;
use nystudio107\imageoptimize\jobs\ResaveOptimizedImages;
use yii\base\InvalidConfigException;
use yii\console\Controller;
use yii\helpers\Console;
use yii\web\NotFoundHttpException;

/**
 * Element Relations Caching
 *
 * The first line of this class docblock is displayed as the description
 * of the Console Command in ./craft help
 *
 * Craft can be invoked via commandline console by using the `./craft` command
 * from the project root.
 *
 * Console Commands are just controllers that are invoked to handle console
 * actions. The segment routing is plugin-name/controller-name/action-name
 *
 * The actionIndex() method is what is executed if no sub-commands are supplied, e.g.:
 *
 * ./craft element-relations/refresh
 *
 * Actions must be in 'kebab-case' so actionDoSomething() maps to 'do-something',
 * and would be invoked via:
 *
 * ./craft element-relations/caches/index
 * ./craft element-relations/caches/create
 * ./craft element-relations/caches/refresh
 *
 */
class CachesController extends Controller
{

    // Public Properties
    // =========================================================================

    /**
     * @var bool Whether caches should be rebuilt, even if they already exist
     * @since 1.0.7
     */
    public $force = false;

    /**
     * @var string name of the volume handle to run caches on
     * @since 1.0.7
     */
    public $volume = null;

    /**
     * @var string name of the section handle to run caches on
     * @since 1.0.7
     */
    public $section = null;

    // Protected Properties
    // =========================================================================

    protected $elementRelationsService;

    // Public Methods
    // =========================================================================

    /**
     * @param $id
     * @param $module
     * @param array $config
     */
    public function __construct($id, $module, $config = [])
    {
        parent::__construct($id, $module, $config);
        $this->elementRelationsService = new ElementRelationsService();
    }

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'force';
        $options[] = 'volume';
        $options[] = 'section';

        return $options;
    }

    /********** Actions available to the console *************/

    /**
     * Refresh existing relations without looking for new ones
     *
     * The first line of this method docblock is displayed as the description
     * of the Console Command in ./craft help
     *
     * @return bool
     * @throws InvalidConfigException
     */
    public function actionRefresh():bool
    {

        echo "Refreshing existing stale relations without creating new ones\n";
        $relations = $this->elementRelationsService->getAllRelations();
        if ($this->force) {
            echo 'Forcing entry relations creation via --force'.PHP_EOL;
        }
        $entriesTotal = count($relations);
        echo "$entriesTotal Relations to update." . PHP_EOL;
        $entryNumber = 1;
        foreach ($relations as $relation) {
            $element = ElementRelationsService::getElementById($relation->elementId, $relation->siteId);
            echo "[$entryNumber/$entriesTotal] " . $element->title;
            $this->cacheSingleElement($element, $this->force);
            $entryNumber++;
        }

        return true;
    }

    /**
     * create or refresh stale caches for related entries
     *
     * The first line of this method docblock is displayed as the description
     * of the Console Command in ./craft help
     *
     * @return mixed
     * @throws InvalidConfigException
     */
    public function actionCreate(): void
    {
        echo "Create or refresh stale existing Element Relations\n";
        if ($this->force) {
            echo 'Forcing entry relations cache clear via --force'.PHP_EOL;
        }
        if ($this->volume) {
            echo "Running specifically for volume {$this->volume}" . PHP_EOL;
            $this->saveVolume($this->volume, $this->force);
        } elseif ($this->section) {
            echo "Running specifically for section {$this->section}" . PHP_EOL;
            $this->saveSection($this->section, $this->force);
        } else {
            $this->saveAllVolumes($this->force);
        }

    }

    /**********  Methods to do the big picture work *************/

    /**
     * Re-cache all the Asset relations
     *
     * @param boolean Should element relations caches be rebuilt?
     *
     */
    protected  function saveAllVolumes(bool $force = false)
    {
        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        foreach ($volumes as $volume) {
            if (is_subclass_of($volume, Volume::class)) {
                /** @var Volume $volume */
                $this->reCacheVolumeAssets($volume, $force);
            }
        }
        // now do the other sections....
    }

    /**
     * Re-cache all the relations
     *
     * @param string $volumeHandle only for this specific volume
     * @param boolean Should element relations caches be rebuilt?
     */
    protected function saveVolume(string $volumeHandle, $force = false): void
    {
        if($volumeHandle == null) {
            echo "VolumeHandle is empty, must be supplied to run a single Asset Volume." . PHP_EOL;
        }
        $volume = Craft::$app->getVolumes()>getVolumeByHandle($volumeHandle);
        echo "VolumeHandle found" . PHP_EOL;
        if (is_subclass_of($volume, Volume::class)) {
            /** @var Volume $volume */
            $this->reCacheVolumeAssets($volume,$force);
        } else {
            echo "VolumeHandle not valid or an error occurred." . PHP_EOL;

        }
    }

    /**
     * Re-cache all the relations
     *
     * @param string $sectionHandle only for this specific section
     * @param boolean Should element relations caches be rebuilt?
     * @throws InvalidConfigException
     */
    protected function saveSection(string $sectionHandle, $force = false): void
    {
        if($sectionHandle == null) {
            echo "Section Handle is empty, must be supplied to run a single Section." . PHP_EOL;
        }
        $section = Craft::$app->getSections()->getSectionByHandle($sectionHandle);
        echo "Section $sectionHandle found" . PHP_EOL;
        if ($section instanceof Section) {
            $entryTypes = $section->getEntryTypes();
            foreach ($entryTypes as $entryType) {
                $this->reCacheEntryTypeEntries($entryType, $force);
            }
        } else {
            echo "Section Handle not valid or an error occurred." . PHP_EOL;

        }
    }

    /********** Methods to do the detail work **********/

    /**
     * Re-cache all the relations
     *
     * @param string $entryTypeHandle
     * @param int $entryTypeId
     * @param boolean Should element relations caches be rebuilt?
     * @throws InvalidConfigException
     */
    protected function saveEntryType(string $entryTypeHandle, int $entryTypeId = 0, $force = false): void

    {
        if($entryTypeHandle == null) {
            echo "EntryType Handle is empty, must be supplied to run a single Entry Type." . PHP_EOL;
        }
        $entryType = Craft::$app->getSections()->getEntryTypeById($entryTypeId);
        if ($entryType instanceof EntryType) {
            echo "EntryType {$entryTypeHandle} found" . PHP_EOL;
            $this->reCacheEntryTypeEntries($entryType, $force);
        }
    }

    /**
     * @param EntryType $entryType
     * @param bool $force
     * @throws InvalidConfigException
     */
    protected function reCacheEntryTypeEntries(EntryType $entryType, bool $force): void
    {
        $fieldLayout = $entryType->getFieldLayout();
        $section = $entryType->getSection();
        $processEntryType = false;
        // Loop through the fields in the layout to see if there is an ElementRelations field
        if ($fieldLayout) {
            $fields = $fieldLayout->getFields();
            foreach ($fields as $field) {
                if ($field instanceof ElementRelationsField) {
                    $processEntryType = true;
                    break;
                }
            }
        }
        if ($processEntryType) {
            $entriesInEntryType = \craft\elements\Entry::find()->typeId($entryType->id)->all();
            $entriesTotal = count($entriesInEntryType);
            echo "$entriesTotal Entries found in Section/EntryType {$section->name}/{$entryType->name}." . PHP_EOL;
            $entryNumber = 1;
            foreach ($entriesInEntryType as $entry) {
                echo "[$entryNumber/$entriesTotal] " . $entry->title;
                $this->cacheSingleElement($entry, $force);
                $entryNumber++;
            }
        }
    }

    /**
     * Re-cache relations of the Asset elements in the Volume $volume that have an
     * RelatedElements field in the FieldLayout
     * Code lovingly adapted from nystudio107
     *
     * @param Volume $volume for this volume
     * @param boolean Should element relations caches be rebuilt?
     *
     */
    protected function reCacheVolumeAssets(Volume $volume, $force = false)
    {
        echo "Volume " . $volume->name . PHP_EOL;
        $needToReSave = false;
        /** @var FieldLayout $fieldLayout */
        $fieldLayout = $volume->getFieldLayout();
        // Loop through the fields in the layout to see if there is an ElementRelations field
        if ($fieldLayout) {
            $fields = $fieldLayout->getFields();
            foreach ($fields as $field) {
                echo $field->name . PHP_EOL;
                if ($field instanceof ElementRelationsField) {
                    $needToReSave = true;
                }
            }
        }
        if ($needToReSave) {
            try {
                $siteId = Craft::$app->getSites()->getPrimarySite()->id;
            } catch (SiteNotFoundException $e) {
                $siteId = 0;
                Craft::error(
                    'Failed to get primary site: ' . $e->getMessage(),
                    __METHOD__
                );
            }

            $assets = Asset::find()
                ->volume($volume)
                ->all();
            $assetTotal = count($assets);
            echo "$assetTotal Assets found in volume." . PHP_EOL;
            $assetCount = 1;
            foreach ($assets as $asset) {
                echo "[$assetCount/$assetTotal] ". $asset->title;
                $this->cacheSingleElement($asset, $force);
                $assetCount++;
            }

        }
    }

    /********** Atomic Methods ************/

    /**
     * @param Element | ElementInterface $element
     * @param bool $force
     * @return void
     */
    private function cacheSingleElement(Element $element, bool $force = false): void
    {

        if (!$element->id) {
            return;
        }
        $elementId = $element->id;
        $siteId = $element->siteId;
        $this->elementRelationsService->getRelations($element, $elementId, $siteId, 'default', $force);
        echo "...Done" . PHP_EOL;
    }

}
