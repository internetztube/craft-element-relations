<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Extractors;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use Internetztube\ElementRelations\Models\CacheRecord;

interface InterfaceElementExtractor
{
    /**
     * The element classes this extractor reads (strings, so plugin classes need not be installed). Elements of these
     * types are refreshed on every save, since their references live outside custom fields and have no dirty-field signal.
     * @return list<string>
     */
    public static function elementTypes(): array;

    /**
     * One record, several records, or false when the element has no such relation.
     * @return CacheRecord|list<CacheRecord>|false
     */
    public static function getRelations(ElementInterface $element, CacheRecord $baseRecord): CacheRecord|array|false;
}
