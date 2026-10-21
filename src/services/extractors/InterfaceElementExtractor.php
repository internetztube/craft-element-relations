<?php

namespace internetztube\elementRelations\services\extractors;

use craft\base\ElementInterface;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

interface InterfaceElementExtractor
{
    /**
     * The element classes this extractor reads (strings, so plugin classes need not be installed). Elements of these
     * types are refreshed on every save, since their references live outside custom fields and have no dirty-field signal.
     * @return string[]
     */
    public static function elementTypes(): array;

    /**
     * One record, several records, or false when the element has no such relation.
     * @return ElementRelationsCacheRecord|ElementRelationsCacheRecord[]|false
     */
    public static function getRelations(ElementInterface $element, ElementRelationsCacheRecord $baseRecord): ElementRelationsCacheRecord|array|false;
}
