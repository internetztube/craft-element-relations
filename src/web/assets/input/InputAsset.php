<?php

namespace internetztube\elementRelations\web\assets\input;

use Craft;
use craft\web\AssetBundle;
use yii\web\JqueryAsset;

/**
 * Input asset bundle
 */
class InputAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';
    public $depends = [
        JqueryAsset::class,
    ];
    public $js = [
        'jquery-pagination.js'
    ];
    public $css = [];
}
