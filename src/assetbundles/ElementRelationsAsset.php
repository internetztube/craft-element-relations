<?php

namespace internetztube\elementRelations\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class ElementRelationsAsset extends AssetBundle
{
    public function init()
    {
        $this->sourcePath = '@internetztube/elementRelations/web/assets/dist';

        $this->js = [
            'input.js'
        ];

        $this->css = [
            'input.css'
        ];

        $this->depends = [];
        parent::init();
    }
}
