<?php

namespace internetztube\elementRelations\services\extractors;

use craft\base\ElementInterface;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

interface InterfaceElementExtractor
{
    public static function getRelations(ElementInterface $element, ElementRelationsCacheRecord $baseRecord): ElementRelationsCacheRecord|false;
}
