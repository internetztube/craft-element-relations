<?php

declare(strict_types=1);

namespace internetztube\elementRelations\services\extractors;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use internetztube\elementRelations\models\GlobalUsage;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

/**
 * SEOmatic keeps SEO / Twitter / Open Graph image ids in the JSON `metaBundleSettings` column of every meta bundle:
 * the global settings of each site, and the per-section / category group / product type content settings.
 * One cache row per (bundle, asset); `globalKey` is the bundle id.
 */
class GlobalUsageSeomaticService implements InterfaceGlobalUsage
{
    private const IMAGE_ID_KEYS = ['seoImageIds', 'twitterImageIds', 'ogImageIds'];

    public static function type(): string
    {
        return ElementRelationsCacheRecord::TYPE_GLOBAL_SEOMATIC;
    }

    public static function refresh(): void
    {
        $table = ElementRelationsCacheRecord::tableName();
        $db = Craft::$app->getDb();
        // Not before the plugin's own migrations: `craft up` runs Craft's first, and one that saves sections makes
        // SEOmatic save its bundles. m261019_120000_global_usages adds the column and writes these rows.
        if (!$db->columnExists($table, 'globalKey')) {
            return;
        }
        $rows = [];
        if (Craft::$app->getPlugins()->isPluginEnabled('seomatic')) {
            $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;
            $now = Db::prepareDateForDb(new \DateTime());
            /** @var list<array{id: int|string, sourceSiteId: int|string|null, metaBundleSettings: string|resource|null}> $bundles */
            $bundles = (new Query())
                ->select(['id', 'sourceSiteId', 'metaBundleSettings'])
                ->from([\nystudio107\seomatic\records\MetaBundle::tableName()])
                ->all();
            foreach ($bundles as $bundle) {
                $json = $bundle['metaBundleSettings'];
                // PostgreSQL may hand out the JSON column as a stream
                /** @var array<string, int|string|list<int|string>> $settings */
                $settings = json_decode(is_resource($json) ? (string)stream_get_contents($json) : (string)$json, true) ?: [];
                $assetIds = [];
                foreach (self::IMAGE_ID_KEYS as $key) {
                    foreach ((array)($settings[$key] ?? []) as $id) {
                        if ((int)$id) {
                            $assetIds[(int)$id] = true;
                        }
                    }
                }
                foreach (array_keys($assetIds) as $assetId) {
                    $rows[] = [$assetId, (int)($bundle['sourceSiteId'] ?: $primarySiteId), self::type(), (string)$bundle['id'], $now, $now, StringHelper::UUID()];
                }
            }
        }

        $db->transaction(function () use ($db, $table, $rows): void {
            $db->createCommand()->delete($table, ['type' => self::type()])->execute();
            if ($rows) {
                $db->createCommand()->batchInsert($table, ['targetElementId', 'targetSiteId', 'type', 'globalKey', 'dateCreated', 'dateUpdated', 'uid'], $rows)->execute();
            }
        });
    }

    public static function usages(array $rows): array
    {
        if (!$rows || !Craft::$app->getPlugins()->isPluginEnabled('seomatic')) {
            return [];
        }
        /** @var list<array{id: int|string, sourceBundleType: string, sourceHandle: string, sourceName: string, sourceSiteId: int|string|null}> $bundles */
        $bundles = (new Query())
            ->select(['id', 'sourceBundleType', 'sourceHandle', 'sourceName', 'sourceSiteId'])
            ->from([\nystudio107\seomatic\records\MetaBundle::tableName()])
            ->where(['id' => array_column($rows, 'globalKey')])
            ->orderBy(['sourceBundleType' => SORT_ASC, 'sourceName' => SORT_ASC])
            ->all();

        $multiSite = Craft::$app->getIsMultiSite();
        $usages = [];
        foreach ($bundles as $row) {
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
