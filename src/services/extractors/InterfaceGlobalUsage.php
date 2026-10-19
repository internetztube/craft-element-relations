<?php

namespace internetztube\elementRelations\services\extractors;

use internetztube\elementRelations\models\GlobalUsage;

/**
 * Finds usages of an element that are not elements themselves (plugin settings, global configuration).
 * Evaluated at read time, not cached; keep implementations to one cheap query.
 */
interface InterfaceGlobalUsage
{
    /**
     * @return GlobalUsage[]
     */
    public static function usages(int $elementId): array;
}
