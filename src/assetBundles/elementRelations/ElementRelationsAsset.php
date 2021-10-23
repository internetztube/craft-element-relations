<?php

namespace internetztube\elementrRelations\assetBundles\elementRelations;

use Craft;
use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class ElementRelationsAsset extends AssetBundle
{
    public function init()
    {
        $this->sourcePath = "@internetztube/elementrelations/assetbundles/elementrelations/dist";
        $this->depends = [CpAsset::class];
        $this->js = ['js/element-relations.js'];
        $this->css = ['css/element-relations.css'];
        parent::init();
    }
}
