<?php

namespace internetztube\elementRelations\assetBundles\elementRelations;

use Craft;
use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class ElementRelationsAsset extends AssetBundle
{
    public function init()
    {
        $this->sourcePath = "@internetztube/elementRelations/assetBundles/elementRelations/dist";
        $this->depends = [CpAsset::class];
        $this->js = ['js/element-relations.js'];
        $this->css = ['css/element-relations.css'];
        parent::init();
    }
}
