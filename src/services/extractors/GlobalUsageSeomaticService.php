<?php

namespace internetztube\elementRelations\services\extractors;

use Craft;
use craft\db\Query;
use craft\helpers\UrlHelper;
use internetztube\elementRelations\models\GlobalUsage;
use internetztube\elementRelations\services\DatabaseService;

/**
 * SEOmatic keeps SEO / Twitter / Open Graph image ids in the JSON `metaBundleSettings` column of every meta bundle:
 * the global settings of each site, and the per-section / category group / product type content settings.
 */
class GlobalUsageSeomaticService implements InterfaceGlobalUsage
{
    private const IMAGE_ID_KEYS = ['seoImageIds', 'twitterImageIds', 'ogImageIds'];

    public static function usages(int $elementId): array
    {
        if (!Craft::$app->getPlugins()->isPluginEnabled('seomatic')) {
            return [];
        }

        // Each key holds a JSON array of string or int ids: ["5"], [5, 12], ["5", "12"], ...
        // Normalize to [5,12] (no quotes, no spaces) and match the id at any position.
        $conditions = ['or'];
        foreach (self::IMAGE_ID_KEYS as $key) {
            $ids = 'REPLACE(REPLACE(' . DatabaseService::jsonExtract('metaBundleSettings', [$key]) . ", '\"', ''), ' ', '')";
            array_push(
                $conditions,
                ['like', $ids, "[$elementId]", false],
                ['like', $ids, "[$elementId,%", false],
                ['like', $ids, "%,$elementId]", false],
                ['like', $ids, "%,$elementId,%", false],
            );
        }

        $rows = (new Query())
            ->select(['id', 'sourceBundleType', 'sourceHandle', 'sourceName', 'sourceSiteId'])
            ->from([\nystudio107\seomatic\records\MetaBundle::tableName()])
            ->where($conditions)
            ->orderBy(['sourceBundleType' => SORT_ASC, 'sourceName' => SORT_ASC])
            ->all();

        $multiSite = Craft::$app->getIsMultiSite();
        $usages = [];
        foreach ($rows as $row) {
            $site = $row['sourceSiteId'] ? Craft::$app->getSites()->getSiteById((int)$row['sourceSiteId']) : null;
            $siteSuffix = $multiSite && $site ? "/{$site->handle}" : '';
            $siteLabel = $multiSite && $site ? " ({$site->name})" : '';

            if ($row['sourceBundleType'] === \nystudio107\seomatic\services\MetaBundles::GLOBAL_META_BUNDLE) {
                $usages[] = new GlobalUsage([
                    'provider' => 'seomatic',
                    'key' => $row['id'],
                    'label' => Craft::t('element-relations', 'seomatic-global') . $siteLabel,
                    'url' => UrlHelper::cpUrl('seomatic/global/general' . $siteSuffix),
                    'icon' => 'gear',
                ]);
            } else {
                $usages[] = new GlobalUsage([
                    'provider' => 'seomatic',
                    'key' => $row['id'],
                    'label' => Craft::t('element-relations', 'seomatic-content', ['name' => $row['sourceName']]) . $siteLabel,
                    'url' => UrlHelper::cpUrl("seomatic/edit-content/general/{$row['sourceBundleType']}/{$row['sourceHandle']}" . $siteSuffix),
                    'icon' => 'sliders',
                ]);
            }
        }
        return $usages;
    }
}
