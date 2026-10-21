<?php

namespace internetztube\elementRelations\services\extractors;

use craft\base\ElementInterface;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

interface InterfaceElementExtractor
{
    /**
     * One record, several records, or false when the element has no such relation.
     * @return ElementRelationsCacheRecord|ElementRelationsCacheRecord[]|false
     */
    public static function getRelations(ElementInterface $element, ElementRelationsCacheRecord $baseRecord): ElementRelationsCacheRecord|array|false;
}
