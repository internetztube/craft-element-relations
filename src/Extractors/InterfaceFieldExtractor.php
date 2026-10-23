<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Extractors;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Field\Field;
use Internetztube\ElementRelations\Models\CacheRecord;

interface InterfaceFieldExtractor
{
    /**
     * The relations this field's value holds; false or [] lets the next extractor try (see ExtractorService::fieldRelations()).
     * `$baseRecord` carries the source columns; extractors clone it per relation.
     * @return list<CacheRecord>|false
     */
    public static function getRelations(Field $field, ElementInterface $element, CacheRecord $baseRecord): array|false;
}
