<?php

declare(strict_types=1);

namespace internetztube\elementRelations\services\extractors;

use craft\base\ElementInterface;
use craft\base\Field;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

interface InterfaceFieldExtractor
{
    /**
     * The relations this field's value holds; false or [] lets the next extractor try (see ExtractorService::fieldRelations()).
     * `$baseRecord` carries the source columns; extractors clone it per relation.
     * @return ElementRelationsCacheRecord[]|false
     */
    public static function getRelations(Field $field, ElementInterface $element, ElementRelationsCacheRecord $baseRecord): array|false;
}
