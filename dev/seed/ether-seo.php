<?php
/**
 * SEO by Ether (handle `seo`, not SEOmatic): "Page via SEO" uses the test asset as Facebook and Twitter image,
 * "Page via SEO (no image)" has only a title (must relate to nothing).
 */

use ether\seo\fields\SeoField;

return function (array $ctx): void {
    $field = ensureField(SeoField::class, ['name' => 'Ether SEO', 'handle' => 'etherSeo']);
    addFieldToLayout($field, $ctx['entryType']);
    // Same shape the CP posts (`social[<network>][image]` = id string); the plugin keeps it as SocialData::$imageId.
    ensureEntry('Page via SEO', ['etherSeo' => ['title' => 'Page via SEO', 'social' => [
        'facebook' => ['image' => (string)$ctx['asset']->id],
        'twitter' => ['image' => (string)$ctx['asset']->id],
    ]]]);
    ensureEntry('Page via SEO (no image)', ['etherSeo' => ['title' => 'Page via SEO (no image)']]);
};
