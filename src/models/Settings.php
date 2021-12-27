<?php

namespace internetztube\elementRelations\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * Cache Duration used in a strtotime() function, like '5h' or '2 days' or '1 month'.
     * @var string
     */
    public $cacheDuration = '2 weeks';

    /**
     * Whether to cache element relations in the db or load them every time.
     * @var bool
     */
    public $useCache = true;
}
