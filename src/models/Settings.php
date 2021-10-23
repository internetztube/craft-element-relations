<?php

namespace internetztube\elementTelations\models;

use internetztube\elementRelations\ElementRelations;

use Craft;
use craft\base\Model;

class Settings extends Model
{
    public $someAttribute = 'Some Default';

    public function rules()
    {
        return [
            ['someAttribute', 'string'],
            ['someAttribute', 'default', 'value' => 'Some Default'],
        ];
    }
}
