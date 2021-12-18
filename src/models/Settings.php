<?php

namespace internetztube\elementRelations\models;


use craft\base\Model;

/**
 * @package ElementRelations
 */
class Settings extends Model
{

    /**
     * @var string Cache Duration used in a strtotime() function, like '5h' or '2 days' or '1 month'
     */
    public $cacheDuration = '2 weeks';

    /**
     * @var bool Whether to cache element relations in the db or load them every time
     */
    public $useCache = true;

}
