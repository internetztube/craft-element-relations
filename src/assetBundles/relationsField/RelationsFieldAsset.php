<?php
/**
 * element-relations plugin for Craft CMS 3.x
 *
 * Indicates where a certain element is used.
 *
 * @link      https://frederickoeberl.com/
 * @copyright Copyright (c) 2021 Frederic Koeberl
 */

namespace internetztube\elementRelations\assetBundles\relationsField;

use Craft;
use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class RelationsFieldAsset extends AssetBundle
{
    public function init()
    {
        $this->sourcePath = "@internetztube/elementRelations/assetBundles/relationsField/dist";
        $this->depends = [CpAsset::class];
        $this->js = ['js/relations.js',];
        $this->css = ['css/relations.css'];
        parent::init();
    }
}
