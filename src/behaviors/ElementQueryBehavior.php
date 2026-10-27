<?php

declare(strict_types=1);

namespace internetztube\elementRelations\behaviors;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use yii\base\Behavior;
use yii\db\Expression;

/**
 * Adds the `usesElement` parameter to every element query: only elements that use (relate to) the given element(s),
 * according to the relations cache. Nested elements count for their top-level owner.
 *
 * ```twig
 * {% set pages = craft.entries().usesElement(asset).all() %}
 * ```
 * Also usable as element index criteria (`{usesElement: 123}`), which is how the field renders its native indexes.
 * `usesElementSite` narrows it to the target in that site (or sites): the element × site whose usages the field lists.
 * `unused()` is the inverse for a single element type: `craft.assets().unused()` lists assets nothing relates to, in any
 * site; `unused(false)` the ones something relates to; null (the default) does not filter.
 *
 * @property ElementQuery<array-key, ElementInterface> $owner
 */
class ElementQueryBehavior extends Behavior
{
    /** @var int|string|ElementInterface|array<int|string|ElementInterface>|null ids arrive as strings from element index criteria */
    public mixed $usesElement = null;

    /** @var int|string|array<int|string>|null with `usesElement`: only relations to the target in this site (or these sites) */
    public mixed $usesElementSite = null;

    /** Only elements nothing relates to: no cache row, which includes non-element usages such as SEOmatic settings. */
    public ?bool $unused = null;

    /**
     * @param int|string|ElementInterface|array<int|string|ElementInterface>|null $value
     * @return ElementQuery<array-key, ElementInterface>
     */
    public function usesElement(mixed $value): ElementQuery
    {
        $this->usesElement = $value;
        return $this->owner;
    }

    /**
     * @param int|string|array<int|string>|null $value site id(s) of the target
     * @return ElementQuery<array-key, ElementInterface>
     */
    public function usesElementSite(mixed $value): ElementQuery
    {
        $this->usesElementSite = $value;
        return $this->owner;
    }

    /** @return ElementQuery<array-key, ElementInterface> */
    public function unused(?bool $value = true): ElementQuery
    {
        $this->unused = $value;
        return $this->owner;
    }

    /** @return array<string, string> */
    public function events(): array
    {
        return [ElementQuery::EVENT_AFTER_PREPARE => 'applyParams'];
    }

    /** Runs after Craft created the sub query, which is rebuilt on every prepare (count, all, ...). */
    public function applyParams(): void
    {
        /** @var Query<array-key, mixed> $subQuery created by prepare() before this event */
        $subQuery = $this->owner->subQuery;
        // an index sorted by the field: its order is the usage count (ElementRelationsField::getSortOption())
        $order = (array)$this->owner->orderBy;
        $sortsByUsages = str_contains(implode(' ', [...array_keys($order), ...array_map(fn(mixed $value): string => is_scalar($value) || $value instanceof \Stringable ? (string)$value : '', $order)]), 'elementrelations_cache');
        if ($this->usesElement !== null || $this->unused !== null || $sortsByUsages) {
            // Craft caches element index queries for an hour, invalidated by the element type's cache tags. These
            // results (and the order by usage count) also change when *other* elements are saved (a relation added
            // somewhere, a source renamed or disabled) or the cache is refreshed by a queue job, which those tags never
            // cover, so never serve them from the query cache.
            $this->owner->queryCacheDuration = null;
            $this->owner->queryCacheDependency = null;
        }
        if ($this->unused !== null) {
            $subQuery->andWhere([$this->unused ? 'not exists' : 'exists', (new Query())
                ->select(['elementrelations_cache.id'])
                ->from(['elementrelations_cache' => '{{%elementrelations_cache}}'])
                ->where(['elementrelations_cache.targetElementId' => new Expression('[[elements.id]]')])
                // usages by trashed elements don't count, like everywhere else (they come back when restored);
                // plugin settings (no source element) do. A correlated EXISTS rather than a join, see
                // ElementRelationsField::getSortOption().
                ->andWhere(['or', ['elementrelations_cache.sourceElementId' => null], ['exists', (new Query())
                    ->select(new Expression('1'))
                    ->from(['sources' => Table::ELEMENTS])
                    ->where(['sources.id' => new Expression('[[elementrelations_cache.sourcePrimaryOwnerId]]')])
                    ->andWhere(['sources.dateDeleted' => null, 'sources.type' => Craft::$app->getElements()->getAllElementTypes()]),
                ]]),
            ]);
        }
        if ($this->usesElement === null) {
            return;
        }

        $targetIds = [];
        foreach (is_array($this->usesElement) ? $this->usesElement : [$this->usesElement] as $target) {
            $targetIds[] = $target instanceof ElementInterface ? (int)$target->id : (int)$target;
        }
        $targetSiteIds = self::targetSiteIds($this->usesElementSite);

        // a list spanning every site shows each element once, in the site the index asked for when it uses the
        // target there, else its first site that does (the Site column names all of them)
        $owner = $this->owner;
        $allSites = $owner instanceof \internetztube\elementRelations\elements\db\UsageQuery && $owner->allSites;
        $only = $allSites ? array_map(intval(...), array_filter((array)$owner->inSites, is_numeric(...))) : [];
        $preferred = $allSites ? (int)($owner->preferredSiteId ?? 0) : 0;

        // The using elements × sites come from the cache first and go into the query as literal ids. A correlated
        // EXISTS (or a join on the cache) behind Craft's 8+ joins makes Postgres scan every element and element site
        // before it looks at the cache: 10 s on 2M elements instead of 0.1 s via the primary key.
        // ponytail: one id per using element in the SQL text (100,000 ids: about 0.7 MB of SQL)
        $sitesByOwner = [];
        foreach ((new Query())
            ->select(['owner' => 'sourcePrimaryOwnerId', 'site' => 'sourceSiteId'])
            ->distinct()
            ->from(['{{%elementrelations_cache}}'])
            ->where(['targetElementId' => $targetIds])
            ->andFilterWhere(['targetSiteId' => $targetSiteIds, 'sourceSiteId' => $only ?: null])
            ->all() as $row) {
            /** @var array{owner: int|string, site: int|string} $row */
            $sitesByOwner[(int)$row['owner']][] = (int)$row['site'];
        }
        $ownersBySite = [];
        foreach ($sitesByOwner as $ownerId => $sites) {
            foreach ($allSites ? [in_array($preferred, $sites, true) ? $preferred : min($sites)] : $sites as $site) {
                $ownersBySite[$site][] = $ownerId;
            }
        }
        if (!$ownersBySite) {
            $subQuery->andWhere('0=1'); // nothing uses it
            return;
        }
        // literal ids, not one bound parameter each: PostgreSQL takes at most 65,535 (dev/stress.php: 100,000 usages)
        $subQuery->andWhere(['or', ...array_map(
            fn(int $site) => ['and', ['elements_sites.siteId' => $site], \internetztube\elementRelations\ElementRelations::idIn('elements.id', $ownersBySite[$site])],
            array_keys($ownersBySite),
        )]);
    }

    /**
     * `usesElementSite` as site ids (they arrive as strings from element index criteria); null when not given.
     * @return list<int>|null
     */
    public static function targetSiteIds(mixed $value): ?array
    {
        $ids = array_values(array_map(intval(...), array_filter((array)$value, is_numeric(...))));
        return $ids ?: null;
    }
}
