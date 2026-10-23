<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Elements\Queries;

use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Queries\Contracts\ElementQueryInterface;
use CraftCms\Cms\Element\Queries\ElementQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Models\CacheRecord;

/**
 * The `usesElement` parameter of every element query: only elements that use (relate to) the given element(s),
 * according to the relations cache. Nested elements count for their top-level owner.
 *
 * ```twig
 * {% set pages = craft.entries().usesElement(asset).all() %}
 * ```
 * `usesElementSite` narrows it to the target in that site (or sites): the element × site whose usages the field lists.
 * `unused()` is the inverse for a single element type: `craft.assets().unused()` lists assets nothing relates to, in any
 * site; `unused(false)` the ones something relates to; null (the default) does not filter.
 *
 * Macros on Craft's ElementQuery (registered in ElementRelations::boot()). Their values live in the query's own
 * `config` array, so clones carry them like any other param. UsageQuery has them as real properties instead, so
 * element index criteria (`usesElement: 12`) reach it.
 */
final class UsesElement
{
    public const KEY = 'element-relations';
    private const PARAMS = ['usesElement' => null, 'usesElementSite' => null, 'unused' => null];

    public static function register(): void
    {
        ElementQuery::macro('usesElement', function (mixed $value): ElementQuery {
            /** @var ElementQuery<ElementInterface> $this */
            return UsesElement::set($this, 'usesElement', $value);
        });
        ElementQuery::macro('usesElementSite', function (mixed $value): ElementQuery {
            /** @var ElementQuery<ElementInterface> $this */
            return UsesElement::set($this, 'usesElementSite', $value);
        });
        ElementQuery::macro('unused', function (?bool $value = true): ElementQuery {
            /** @var ElementQuery<ElementInterface> $this */
            return UsesElement::set($this, 'unused', $value);
        });
    }

    /**
     * @param ElementQuery<ElementInterface> $query
     * @return ElementQuery<ElementInterface>
     */
    public static function set(ElementQuery $query, string $param, mixed $value): ElementQuery
    {
        $state = self::state($query);
        if ($state === null) {
            // the first of the params on this query: apply them when it runs
            $query->beforeQuery(static fn(ElementQuery $query) => self::apply($query, self::params($query)));
        }
        $state = ($state ?? self::PARAMS);
        $state[$param] = $value;
        (function () use ($state): void {
            /** @var ElementQuery<ElementInterface> $this */
            $this->config[UsesElement::KEY] = $state;
        })->call($query);
        return $query;
    }

    /**
     * The params of a query (UsageQuery: its properties).
     * @param ElementQuery<ElementInterface> $query
     * @return array{usesElement: mixed, usesElementSite: mixed, unused: bool|null}
     */
    public static function params(ElementQuery $query): array
    {
        if ($query instanceof UsageQuery) {
            return ['usesElement' => $query->usesElement, 'usesElementSite' => $query->usesElementSite, 'unused' => null];
        }
        /** @var array{usesElement: mixed, usesElementSite: mixed, unused: bool|null} */
        return self::state($query) ?? self::PARAMS;
    }

    /**
     * @param ElementQuery<ElementInterface> $query
     * @return array{usesElement: mixed, usesElementSite: mixed, unused: bool|null}|null
     */
    private static function state(ElementQuery $query): ?array
    {
        /** @var array{usesElement: mixed, usesElementSite: mixed, unused: bool|null}|null */
        return (fn(): mixed => $this->config[UsesElement::KEY] ?? null)->call($query);
    }

    /**
     * Narrows the query to the elements using the targets (see the class docs).
     * @param ElementQuery<ElementInterface> $query
     * @param array{usesElement: mixed, usesElementSite: mixed, unused: bool|null} $params
     */
    public static function apply(ElementQuery $query, array $params): void
    {
        if ($params['usesElement'] !== null || $params['unused'] !== null) {
            // These results change when *other* elements are saved (a relation added somewhere, a source renamed or
            // disabled), which the query's cache tags never cover, so never serve them from the query cache.
            $query->noCache();
        }
        if ($params['unused'] !== null) {
            self::applyUnused($query->getQuery(), $params['unused']);
        }
        if ($params['usesElement'] !== null) {
            self::applyUsesElement($query->getQuery(), $query, $params['usesElement'], $params['usesElementSite']);
        }
    }

    /** Only elements nothing uses (`$unused`), or only those something uses: a cache row, plugin settings included. */
    public static function applyUnused(Builder $builder, bool $unused): void
    {
        $builder->{$unused ? 'whereNotExists' : 'whereExists'}(fn(Builder $usages) => $usages
            ->selectRaw('1')
            ->from(CacheRecord::TABLE)
            ->whereColumn(CacheRecord::TABLE . '.targetElementId', 'elements.id')
            // usages by trashed elements don't count, like everywhere else (they come back when restored);
            // plugin settings (no source element) do. A correlated EXISTS rather than a join, see
            // ElementRelationsField::getSortOption().
            ->where(fn(Builder $live) => $live
                ->whereNull(CacheRecord::TABLE . '.sourceElementId')
                ->orWhereExists(fn(Builder $sources) => $sources
                    ->selectRaw('1')
                    ->from(Table::ELEMENTS . ' as sources')
                    ->whereColumn('sources.id', CacheRecord::TABLE . '.sourcePrimaryOwnerId')
                    ->whereNull('sources.dateDeleted')
                    ->whereIn('sources.type', ElementRelations::elementTypes()))));
    }

    /**
     * Only the elements (in the sites) that use the targets.
     * @param ElementQueryInterface $query the element query (UsageQuery: its `allSites` options)
     * @param mixed $usesElement an element, an id, or a list of them
     */
    public static function applyUsesElement(Builder $builder, ElementQueryInterface $query, mixed $usesElement, mixed $usesElementSite): void
    {
        $targetIds = [];
        foreach (is_array($usesElement) ? $usesElement : [$usesElement] as $target) {
            $targetIds[] = $target instanceof ElementInterface ? (int)$target->id : (is_numeric($target) ? (int)$target : 0);
        }
        $targetSiteIds = self::targetSiteIds($usesElementSite);

        // a list spanning every site shows each element once, in the site the index asked for when it uses the
        // target there, else its first site that does (the Site column names all of them)
        $allSites = $query instanceof UsageQuery && $query->allSites;
        $only = $query instanceof UsageQuery && $allSites ? array_map(intval(...), array_filter((array)$query->inSites, is_numeric(...))) : [];
        $preferred = $query instanceof UsageQuery && $allSites ? (int)($query->preferredSiteId ?? 0) : 0;

        // The using elements × sites come from the cache first and go into the query as literal ids. A correlated
        // EXISTS (or a join on the cache) behind Craft's joins makes PostgreSQL scan every element and element site
        // before it looks at the cache: 10 s on 2M elements instead of 0.1 s via the primary key.
        // ponytail: one id per using element in the SQL text (100,000 ids: about 0.7 MB of SQL)
        $sitesByOwner = [];
        foreach (DB::table(CacheRecord::TABLE)
            ->select(['sourcePrimaryOwnerId as owner', 'sourceSiteId as site'])
            ->distinct()
            ->whereIn('targetElementId', $targetIds)
            ->whereNotNull('sourcePrimaryOwnerId')
            ->when($targetSiteIds !== null, fn(Builder $rows) => $rows->whereIn('targetSiteId', (array)$targetSiteIds))
            ->when($only !== [], fn(Builder $rows) => $rows->whereIn('sourceSiteId', $only))
            ->get() as $row) {
            /** @var object{owner: int|string, site: int|string} $row */
            $sitesByOwner[(int)$row->owner][] = (int)$row->site;
        }
        $ownersBySite = [];
        foreach ($sitesByOwner as $ownerId => $sites) {
            foreach ($allSites ? [in_array($preferred, $sites, true) ? $preferred : min($sites)] : $sites as $site) {
                $ownersBySite[$site][] = $ownerId;
            }
        }
        if (!$ownersBySite) {
            $builder->whereRaw('0 = 1'); // nothing uses it
            return;
        }
        // literal ids, not one bound parameter each: PostgreSQL takes at most 65,535 (dev/stress.php: 100,000 usages)
        $builder->where(function (Builder $pairs) use ($ownersBySite): void {
            foreach ($ownersBySite as $site => $ids) {
                $pairs->orWhere(fn(Builder $pair) => $pair->where('elements_sites.siteId', $site)->whereIntegerInRaw('elements.id', $ids));
            }
        });
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
