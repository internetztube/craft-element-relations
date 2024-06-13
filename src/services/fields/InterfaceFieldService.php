<?php

namespace internetztube\elementRelations\services\fields;

use craft\base\ElementInterface;
use craft\db\Query;

interface InterfaceFieldService
{
    public static function getElementsSitesQuery(ElementInterface $element): ?Query;
}