<?php

namespace internetztube\elementRelations\console\controllers;

use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Volume;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\errors\SiteNotFoundException;
use craft\helpers\Cp;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use internetztube\elementRelations\ElementRelations;

use Craft;
use internetztube\elementRelations\fields\ElementRelationsField;
use internetztube\elementRelations\services\CacheService;
use internetztube\elementRelations\services\ElementRelationsService;
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
 * ./craft element-relations/caches/index //not implemented yet
 * ./craft element-relations/caches/create
 * ./craft element-relations/caches/refresh
 *
 * @todo implement index action to at least not error out. Maybe indicate volumes/sections using the field?
 * @todo add siteId to calls to restrict to one site id
 * @todo move refresh and create calls to Jobs so they enqueue and run when available
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
     * @var string Volume handle to run caches on
     * @since 1.0.7
     */
    public $volume = null;

    /**
     * @var string Section handle to run caches on
     * @since 1.0.7
     */
    public $section = null;

    /**
     * @var string SiteId to run caches on
     * @since 1.0.7
     */
    public $siteId = null;

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
     * These options will be passed through from the command line to this controller
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'force';
        $options[] = 'volume';
        $options[] = 'section';
        $options[] = 'siteId'; // not currently implemented

        return $options;
    }

    /********** Actions available to the console *************/

    /**
     * Create or refresh stale caches for related entries
     *
     * Allows for single volume, single section, will do all
     * Force will rebuild any cached items
     *
     * @return mixed
     * @throws InvalidConfigException
     */
    public function actionCreate(): void
    {
        echo 'Create or refresh stale existing Element Relations' . PHP_EOL;
        if ($this->force) {
            echo 'Forcing entry relations cache clear via --force' . PHP_EOL;
        }
        if ($this->volume) {
            echo "Running specifically for volume '{$this->volume}'" . PHP_EOL;
            $this->saveVolume($this->volume, $this->force);
        } elseif ($this->section) {
            echo "Running specifically for section '{$this->section}'" . PHP_EOL;
            $this->saveSection($this->section, $this->force);
        } else {
            $this->saveAllSections($this->force);
            $this->saveAllVolumes($this->force);
        }
    }

    /**
     * Refresh existing relations without looking for new ones
     *
     * Looks up all the relations in the table and refreshes if stale or forced
     *
     * @return bool
     */
    public function actionRefresh(): bool
    {
        echo 'Refreshing existing stale relations without creating new ones' . PHP_EOL;
        if ($this->force) {
            echo 'Forcing entry relations creation via --force' . PHP_EOL;
        }
        $relations = CacheService::getAllRelations(!$this->force());
        $entriesTotal = count($relations);
        $cacheDuration = CacheService::getCacheDuration();
        echo "Cache duration set to $cacheDuration" . PHP_EOL;
        if ($entriesTotal == 0) {
            echo "All relations up to date." . PHP_EOL;
        } else {
            echo "$entriesTotal Relations to update." . PHP_EOL;
        }
        $entryNumber = 1;
        foreach ($relations as $relation) {
            $element = ElementRelationsService::getElementById($relation->elementId, $relation->siteId);
            echo "[$entryNumber/$entriesTotal] " . substr($element->title, 0, 50);
            $this->cacheSingleElement($element, $this->force);
            $entryNumber++;
        }

        return true;
    }


    /**********  Methods to do the Volumes work *************/

    /**
     * Re-cache all the Asset relations
     *
     * @param boolean Should element relations caches be rebuilt?
     */
    protected function saveAllVolumes(bool $force = false): void
    {
        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        foreach ($volumes as $volume) {
            if (is_subclass_of($volume, Volume::class)) {
                /** @var Volume $volume */
                $this->reCacheVolumeAssets($volume, $force);
            }
        }
    }

    /**
     * Re-cache all the relations
     *
     * @param string $volumeHandle only for this specific volume
     * @param boolean Should element relations caches be rebuilt?
     */
    protected function saveVolume(string $volumeHandle, $force = false): void
    {
        if ($volumeHandle == null) {
            echo "VolumeHandle is empty, must be supplied to run a single Asset Volume." . PHP_EOL;
        }
        $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);
        if (is_subclass_of($volume, Volume::class)) {
            echo "VolumeHandle '$volumeHandle' found" . PHP_EOL;
            /** @var Volume $volume */
            $this->reCacheVolumeAssets($volume, $force);
        } else {
            echo "VolumeHandle '$volumeHandle' not valid or an error occurred." . PHP_EOL;

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
    protected function reCacheVolumeAssets(Volume $volume, $force = false): void
    {
        echo "Volume " . $volume->name . PHP_EOL;
        $needToReSave = false;
        /** @var FieldLayout $fieldLayout */
        $fieldLayout = $volume->getFieldLayout();
        // Loop through the fields in the layout to see if there is an ElementRelations field
        if ($fieldLayout) {
            $fields = $fieldLayout->getFields();
            foreach ($fields as $field) {
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
                echo "[$assetCount/$assetTotal] " . substr($asset->title, 0, 50);
                $this->cacheSingleElement($asset, $force);
                $assetCount++;
            }
            echo PHP_EOL;
        }
    }


    /********** Methods to do the Sections work **********/

    /**
     * Re-cache all the Section relations
     *
     * @param boolean Should element relations caches be rebuilt?
     * @throws InvalidConfigException
     */
    protected function saveAllSections(bool $force = false): void
    {
        $entryTypes = $this->elementRelationsService->getRelatedElementEntryTypes();
        foreach ($entryTypes as $id => $entryType) {
            $this->reCacheEntryTypeEntries($entryType, $force);
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
        if ($sectionHandle == null) {
            echo "Section Handle is empty, must be supplied to run a single Section." . PHP_EOL;
        }
        $section = Craft::$app->getSections()->getSectionByHandle($sectionHandle);
        if ($section instanceof Section) {
            echo "Section '$sectionHandle' found" . PHP_EOL;
            $entryTypes = $section->getEntryTypes();
            foreach ($entryTypes as $entryType) {
                $this->reCacheEntryTypeEntries($entryType, $force);
            }
        } else {
            echo "Section Handle '$sectionHandle' not valid or an error occurred." . PHP_EOL;

        }
    }

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
        if ($entryTypeHandle == null) {
            echo "EntryType Handle is empty, must be supplied to run a single Entry Type." . PHP_EOL;
        }
        $entryType = Craft::$app->getSections()->getEntryTypeById($entryTypeId);
        if ($entryType instanceof EntryType) {
            echo "EntryType $entryTypeHandle found" . PHP_EOL;
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
            $entriesInEntryType = Entry::find()->typeId($entryType->id)->all();
            $entriesTotal = count($entriesInEntryType);
            echo "$entriesTotal Entries found in Section/EntryType {$section->name}/{$entryType->name}." . PHP_EOL;
            $entryNumber = 1;
            foreach ($entriesInEntryType as $entry) {
                echo "[$entryNumber/$entriesTotal] " . substr($entry->title, 0, 50);
                $this->cacheSingleElement($entry, $force);
                $entryNumber++;
            }
        }
        echo PHP_EOL;
    }

    /********** Atomic Methods to do the caching ************/

    /**
     * @param Element | ElementInterface $element
     * @param bool $force
     * @return void
     */
    private function cacheSingleElement(Element $element, bool $force = false): void
    {
        if (!$element->id) { return; }
        CacheService::getRelationsCached($element, 'default', $force);
        echo "...Done" . PHP_EOL;
    }

}
