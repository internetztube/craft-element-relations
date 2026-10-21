<?php

namespace internetztube\elementRelations\services\extractors;

use internetztube\elementRelations\models\GlobalUsage;

/**
 * Usages of an element that are not elements themselves (plugin settings, global configuration). They live in the
 * relations cache like everything else, as rows without a source element: `type` names the provider, `globalKey`
 * identifies the setting (e.g. a SEOmatic meta bundle id). So counts, sorting and the "unused" filter see them.
 */
interface InterfaceGlobalUsage
{
    /** The cache row `type` of this provider. */
    public static function type(): string;

    /** Rewrites this provider's cache rows from the current settings. Called on settings changes and full rebuilds. */
    public static function refresh(): void;

    /**
     * Turns this provider's cache rows (`targetElementId`, `targetSiteId`, `globalKey`) into display models.
     * @param array[] $rows
     * @return GlobalUsage[]
     */
    public static function usages(array $rows): array;
}
