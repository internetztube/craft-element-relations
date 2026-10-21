<?php
/**
 * SEOmatic: an SeoSettings field whose SEO image is `$ctx['asset']`, an entry that uses the asset only as Twitter and
 * Open Graph image, and the asset as Twitter image in the global settings (next to a non-existent id, to exercise
 * multi-id JSON matching; the global SEO image list only holds the non-existent id).
 */

use nystudio107\seomatic\fields\SeoSettings;
use nystudio107\seomatic\Seomatic;

return function (array $ctx): void {
    $field = ensureField(SeoSettings::class, ['name' => 'SEO', 'handle' => 'seo']);
    addFieldToLayout($field, $ctx['entryType']);

    // Same shape the CP posts: ids as strings.
    ensureEntry('Page via SEOmatic', ['seo' => [
        'metaGlobalVars' => ['seoTitle' => 'Page via SEOmatic'],
        'metaBundleSettings' => ['seoImageSource' => 'fromAsset', 'seoImageIds' => [(string)$ctx['asset']->id]],
    ]]);

    ensureEntry('Page via SEOmatic (social)', ['seo' => [
        'metaGlobalVars' => ['seoTitle' => 'Page via SEOmatic (social)'],
        'metaBundleSettings' => [
            'twitterImageSource' => 'fromAsset',
            'twitterImageIds' => [(string)$ctx['asset']->id],
            'ogImageSource' => 'fromAsset',
            'ogImageIds' => [(string)$ctx['asset']->id],
        ],
    ]]);

    $bundle = Seomatic::$plugin->metaBundles->getGlobalMetaBundle($ctx['siteId']);
    $bundle->metaBundleSettings->seoImageSource = 'fromAsset';
    $bundle->metaBundleSettings->seoImageIds = [999999];
    $bundle->metaBundleSettings->twitterImageSource = 'fromAsset';
    $bundle->metaBundleSettings->twitterImageIds = [999999, $ctx['asset']->id]; // ints, asset last
    Seomatic::$plugin->metaBundles->updateMetaBundle($bundle, $ctx['siteId']);
};
