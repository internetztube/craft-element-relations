<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Models;

use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Queries\ElementQuery;
use CraftCms\Cms\Element\Queries\EntryQuery;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Events\GlobalUsageProvidersResolving;
use Internetztube\ElementRelations\Extractors\InterfaceGlobalUsage;
use Internetztube\ElementRelations\Support\Sql;

/**
 * Answers "where is this element used?" by querying the relations cache table.
 *
 * `siteIds` means the sites of the *source* elements (the ones holding the relation).
 * `null` = the element's own site, `[]` = all sites.
 * `sections` / `entryTypes` are handles and only restrict entries.
 * `status` is passed through to the element queries; `null` returns elements of every status.
 * `drafts`: `null` = canonical elements and drafts, `false` = canonical only, `true` = drafts only.
 * `types`: only relating elements of these element type classes.
 */
class RelationsModel
{
    /** @var list<class-string<InterfaceGlobalUsage>> usages that are not elements (plugin settings), cached as rows without a source element */
    public const GLOBAL_USAGE_PROVIDERS = [];

    /** @var list<class-string<InterfaceGlobalUsage>>|null */
    private static ?array $providers = null;

    /** @return list<class-string<InterfaceGlobalUsage>> the built-in providers plus the ones plugins add (GlobalUsageProvidersResolving) */
    public static function globalUsageProviders(): array
    {
        if (self::$providers === null) {
            event($event = new GlobalUsageProvidersResolving(self::GLOBAL_USAGE_PROVIDERS));
            self::$providers = array_values(array_filter($event->types, 'class_exists'));
        }
        return self::$providers;
    }

    /** Forgets the registered providers (tests, or plugins registering late). */
    public static function resetProviders(): void
    {
        self::$providers = null;
    }

    private int $elementId;
    private int $siteId;

    /** @var (\Closure(class-string<ElementInterface>): mixed)|null see withQueryFactory() */
    private ?\Closure $queryFactory = null;

    /** @var list<GlobalUsage>|null */
    private ?array $_globalUsages = null;

    /** @var list<array{siteId: int, type: string, count: int}>|null relating elements per source site and element type */
    private ?array $_fastCountQueryResult = null;

    private ?int $_sourceOwnerCount = null;

    /** forTargetSite(): only usages of the element in this site (its version there), wherever they are */
    private ?int $targetSiteId = null;

    /** @var array<class-string<ElementInterface>, array{count: int, drafts: int}>|null relating elements (and drafts among them) per element type, most used first */
    private ?array $_typeCounts = null;

    public function __construct(int $elementId, int $siteId)
    {
        $this->elementId = $elementId;
        $this->siteId = $siteId;
    }

    /**
     * Default of `status` and `drafts`: in site requests what visitors may see (live entries, enabled elements, no
     * drafts), in the control panel and console everything. An explicit `null` always means everything; GraphQL
     * (withQueryFactory()) keeps Craft's resolver defaults.
     */
    public const DEFAULT = 'element-relations:default';

    /**
     * A copy whose element queries come from `$factory($elementType)` instead of `$elementType::find()`; element types
     * for which it returns anything but an ElementQuery are left out. Such queries keep their own default status (a
     * `null` status is not applied) and never include drafts. GraphQL uses this to stay within the active schema.
     * @param callable(class-string<ElementInterface>): mixed $factory
     */
    public function withQueryFactory(callable $factory): static
    {
        $model = clone $this;
        $model->queryFactory = \Closure::fromCallable($factory);
        return $model;
    }

    /**
     * A copy for the element in its own site: counts, lists and plugin settings usages only of that version of it,
     * whichever site the relating elements are in. The control panel's field shows these; Twig and GraphQL keep
     * counting the element in every site (their `siteIds` arguments name the relating elements' sites).
     */
    public function forTargetSite(): static
    {
        $model = clone $this;
        $model->targetSiteId = $this->siteId;
        [$model->_globalUsages, $model->_fastCountQueryResult, $model->_sourceOwnerCount, $model->_typeCounts] = [null, null, null, null];
        return $model;
    }

    /**
     * The sites in which the element's other versions are used, with how many relating elements each (forTargetSite():
     * the sites besides this one), in the sites' order.
     * @return array<int, int> site id => count
     */
    public function getOtherSiteCounts(): array
    {
        $siteId = $this->targetSiteId ?? $this->siteId;
        return $this->prefetched()['otherSites'] ?? self::otherSiteCountsFor([$this->elementId], $siteId)[$this->elementId] ?? [];
    }

    /**
     * @param list<int> $elementIds
     * @return array<int, array<int, int>> element id => site id => relating elements, without $siteId
     */
    private static function otherSiteCountsFor(array $elementIds, int $siteId): array
    {
        $cache = CacheRecord::TABLE;
        $rows = self::fastSubQueryFor($elementIds)
            ->select(["$cache.targetElementId as target", "$cache.targetSiteId as siteId"])
            ->selectRaw('COUNT(DISTINCT ' . Sql::col("$cache.sourcePrimaryOwnerId") . ') as ' . Sql::col('count'))
            ->where("$cache.targetSiteId", '<>', $siteId)
            ->groupBy(["$cache.targetElementId", "$cache.targetSiteId"])
            ->get();
        // plugin settings that use another site's version (SEOmatic's German settings) count too, one per setting
        $globalRows = DB::table($cache)
            ->select(['targetElementId as target', 'targetSiteId as siteId'])
            ->selectRaw('COUNT(DISTINCT ' . Sql::col('globalKey') . ') as ' . Sql::col('count'))
            ->whereIntegerInRaw('targetElementId', $elementIds)
            ->whereNull('sourceElementId')
            ->where('targetSiteId', '<>', $siteId)
            ->groupBy(['targetElementId', 'targetSiteId'])
            ->get();
        $order = array_flip(Sites::getAllSiteIds()->all());
        $counts = [];
        foreach ([...$rows, ...$globalRows] as $row) {
            /** @var object{target: int|string, siteId: int|string, count: int|string} $row */
            $counts[(int)$row->target][(int)$row->siteId] = ($counts[(int)$row->target][(int)$row->siteId] ?? 0) + (int)$row->count;
        }
        foreach ($counts as &$bySite) {
            uksort($bySite, fn(int $a, int $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));
        }
        return $counts;
    }

    /** forTargetSite()'s site (the element's); null: the usages of the element in every site. */
    public function getTargetSiteId(): ?int
    {
        return $this->targetSiteId;
    }

    /** The site of the element (the default of the `siteIds` arguments). */
    public function getSiteId(): int
    {
        return $this->siteId;
    }

    /** The element whose usages this model describes (always the canonical element). */
    public function getElementId(): int
    {
        return $this->elementId;
    }

    /**
     * @param int|int[]|null $siteIds
     * @param string|string[]|null $sections
     * @param string|string[]|null $entryTypes
     * @param string|string[]|null $status null: every status; omitted: see DEFAULT
     * @param class-string<ElementInterface>[]|null $types
     */
    public function getCount(array|int|null $siteIds = null, array|string|null $sections = null, array|string|null $entryTypes = null, array|string|null $status = self::DEFAULT, bool|string|null $drafts = self::DEFAULT, ?array $types = null, ?string $search = null): int
    {
        $queries = $this->getElementQueries($siteIds, $sections, $entryTypes, $status, $drafts, $types, $search);
        return array_sum(array_map(fn(ElementQuery $query): int => $query->count(), $queries));
    }

    /** @param int|int[]|null $siteIds */
    public function getCountFast(array|int|null $siteIds = null): int
    {
        $siteIds = $this->normalizeSiteIds($siteIds);
        if (!$siteIds) {
            return $this->getSourceOwnerCount(); // every site: each element once, as the field's list shows them
        }
        $rows = array_filter(
            $this->getFastCountQueryResult(),
            // loose: site ids may come from templates as strings
            fn(array $row): bool => in_array($row['siteId'], $siteIds),
        );
        return array_sum(array_column($rows, 'count'));
    }

    /** @return array<int, Site> */
    public function getSites(): array
    {
        return Sites::getAllSites()
            ->filter(fn(Site $site): bool => $this->getCount((int)$site->id) > 0)
            ->all();
    }

    /** @return array<int, Site> */
    public function getSitesFast(): array
    {
        $siteIds = array_column($this->getFastCountQueryResult(), 'siteId');
        return Sites::getAllSites()
            ->filter(fn(Site $site): bool => in_array($site->id, $siteIds, true))
            ->all();
    }

    /**
     * @param int|int[]|null $siteIds
     * @param string|string[]|null $sections
     * @param string|string[]|null $entryTypes
     * @param string|string[]|null $status null: every status; omitted: see DEFAULT
     */
    public function getIsInUse(array|int|null $siteIds = null, array|string|null $sections = null, array|string|null $entryTypes = null, array|string|null $status = self::DEFAULT): bool
    {
        return $this->getCount($siteIds, $sections, $entryTypes, $status) > 0 || $this->getGlobalUsages();
    }

    /** @param int|int[]|null $siteIds */
    public function getIsInUseFast(array|int|null $siteIds = null): bool
    {
        return $this->getCountFast($siteIds) > 0 || $this->getGlobalUsages();
    }

    /** Rewrites the cache rows of every non-element usage provider (full rebuilds, migrations). */
    public static function refreshGlobalUsages(): void
    {
        foreach (self::globalUsageProviders() as $provider) {
            $provider::refresh();
        }
    }

    /**
     * Usages that are not elements: plugin settings such as SEOmatic's global SEO image.
     * @return list<GlobalUsage>
     */
    public function getGlobalUsages(): array
    {
        if ($this->_globalUsages === null) {
            $rows = $this->prefetched()['globalRows'] ?? DB::table(CacheRecord::TABLE)
                ->select(['targetElementId', 'targetSiteId', 'type', 'globalKey'])
                ->where('targetElementId', $this->elementId)
                ->whereNull('sourceElementId')
                ->when($this->targetSiteId !== null, fn(Builder $query) => $query->where('targetSiteId', $this->targetSiteId))
                ->get()
                ->map(fn(object $row): array => (array)$row)
                ->all();
            $rowsByType = [];
            foreach ($rows as $row) {
                /** @var array{targetElementId: int|string, targetSiteId: int|string, type: string, globalKey: string|null} $row */
                $rowsByType[$row['type']][] = $row;
            }
            $this->_globalUsages = [];
            foreach (self::globalUsageProviders() as $provider) {
                $this->_globalUsages = [...$this->_globalUsages, ...$provider::usages($rowsByType[$provider::type()] ?? [])];
            }
        }
        return $this->_globalUsages;
    }

    public function getIsUsedInSeomaticGlobalSettings(): bool
    {
        foreach ($this->getGlobalUsages() as $usage) {
            if ($usage->provider === 'seomatic') {
                return true;
            }
        }
        return false;
    }

    /**
     * Element types of the elements that relate to this element (in any site), most used first.
     * @return list<class-string<ElementInterface>>
     */
    public function getElementTypes(): array
    {
        return array_keys($this->getCountsByType());
    }

    /**
     * Number of relating elements per element type (all sites), most used first.
     * @return array<class-string<ElementInterface>, int>
     */
    public function getCountsByType(): array
    {
        return array_map(fn(array $counts): int => $counts['count'], $this->typeCounts());
    }

    /**
     * Elements per type, each once whatever its sites (as the list shows them), and how many of them are drafts (so
     * the element queries can skip the drafts query of a type without any).
     * @return array<class-string<ElementInterface>, array{count: int, drafts: int}>
     */
    private function typeCounts(): array
    {
        if ($this->_typeCounts === null && $this->prefetched() !== null) {
            $this->_typeCounts = $this->prefetched()['types'];
        }
        if ($this->_typeCounts === null) {
            $owner = Sql::col(CacheRecord::TABLE . '.sourcePrimaryOwnerId');
            $rows = $this->fastSubQuery()
                ->select(['elements.type as type'])
                ->selectRaw("COUNT(DISTINCT $owner) as " . Sql::col('count'))
                ->selectRaw('COUNT(DISTINCT CASE WHEN ' . Sql::col('elements.draftId') . " IS NOT NULL THEN $owner END) as " . Sql::col('drafts'))
                ->groupBy('elements.type')
                ->get();
            $counts = [];
            foreach ($rows as $row) {
                /** @var object{type: string, count: int|string, drafts: int|string} $row */
                // element types of uninstalled plugins stay in the cache until their elements are deleted
                if (is_subclass_of($row->type, ElementInterface::class)) {
                    $counts[$row->type] = ['count' => (int)$row->count, 'drafts' => (int)$row->drafts];
                }
            }
            uasort($counts, fn(array $a, array $b): int => $b['count'] <=> $a['count']);
            $this->_typeCounts = $counts;
        }
        return $this->_typeCounts;
    }

    /**
     * Number of relating elements per site, in the order of the site settings.
     * @return array<int, int>
     */
    public function getCountsBySite(): array
    {
        $counts = array_fill_keys(Sites::getAllSiteIds()->all(), 0);
        foreach ($this->getFastCountQueryResult() as $row) {
            if (isset($counts[$row['siteId']])) {
                $counts[$row['siteId']] += $row['count'];
            }
        }
        return array_filter($counts);
    }

    /**
     * IDs of the (primary owner) elements that relate to this element. Cheap: one query, no element hydration.
     * @param int|int[]|null $siteIds
     * @return list<int>
     */
    public function getSourceElementIds(array|int|null $siteIds = null): array
    {
        $siteIds = $this->normalizeSiteIds($siteIds);
        $cache = CacheRecord::TABLE;
        return $this->fastSubQuery()
            ->distinct()
            ->when($siteIds, fn(Builder $query) => $query->whereIn("$cache.sourceSiteId", $siteIds))
            ->pluck("$cache.sourcePrimaryOwnerId")
            ->map(intval(...))
            ->values()
            ->all();
    }

    /** How many (primary owner) elements relate to this element, in any site. One COUNT query. */
    public function getSourceOwnerCount(): int
    {
        return $this->_sourceOwnerCount ??= $this->prefetched()['owners']
            ?? (int)$this->fastSubQuery()->distinct()->count(CacheRecord::TABLE . '.sourcePrimaryOwnerId');
    }

    /**
     * The first `$limit` relating elements, every site (each once), for the element index column: from prefetch() when
     * the index prefetched them (most recently updated first), else getElements().
     * @return list<ElementInterface>
     */
    public function getPreviewElements(int $limit): array
    {
        $prefetched = $this->prefetched();
        if ($prefetched !== null && $prefetched['limit'] >= $limit) {
            return array_slice($prefetched['preview'], 0, $limit);
        }
        return $this->getElements([], $limit);
    }

    /**
     * prefetch()'s results per element and site (`<elementId>:<siteId>`): relating elements in total and per type,
     * plugin settings rows, the first ones, and the other sites' counts.
     * @var array<string, array{owners: int, types: array<class-string<ElementInterface>, array{count: int, drafts: int}>, globalRows: list<array{targetElementId: int|string, targetSiteId: int|string, type: string, globalKey: string|null}>, preview: list<ElementInterface>, limit: int, otherSites: array<int, int>}>
     */
    private static array $prefetched = [];

    /**
     * What prefetch() loaded for this element in this site (forTargetSite() copies only); null when it did not.
     * @return array{owners: int, types: array<class-string<ElementInterface>, array{count: int, drafts: int}>, globalRows: list<array{targetElementId: int|string, targetSiteId: int|string, type: string, globalKey: string|null}>, preview: list<ElementInterface>, limit: int, otherSites: array<int, int>}|null
     */
    private function prefetched(): ?array
    {
        return $this->targetSiteId === null ? null : self::$prefetched[$this->elementId . ':' . $this->targetSiteId] ?? null;
    }

    /** Drops what prefetch() loaded; whenever the cache changes (ExtractorService, PruneService). */
    public static function forgetPrefetched(): void
    {
        self::$prefetched = [];
    }

    /**
     * Loads what an element index column shows for every listed element at once (ElementRelationsField::
     * modifyElementIndexQuery()): five queries or so instead of several per row. The first usages are the most
     * recently updated ones, each in the index's site if it uses the element there, else in its first site that does.
     * Like forTargetSite(): the usages of the elements in the index's site.
     * @param list<int> $elementIds canonical element ids
     */
    public static function prefetch(array $elementIds, int $limit, int $siteId): void
    {
        $preferredSiteId = $siteId;
        $elementIds = array_values(array_unique(array_map(intval(...), $elementIds)));
        $data = array_fill_keys($elementIds, ['owners' => 0, 'types' => [], 'globalRows' => [], 'preview' => [], 'limit' => $limit, 'otherSites' => []]);
        $cache = CacheRecord::TABLE;
        $target = Sql::col("$cache.targetElementId");
        $owner = Sql::col("$cache.sourcePrimaryOwnerId");

        $typeRows = self::fastSubQueryFor($elementIds, $siteId)
            ->select(["$cache.targetElementId as target", 'elements.type as type'])
            ->selectRaw("COUNT(DISTINCT $owner) as " . Sql::col('count'))
            ->selectRaw('COUNT(DISTINCT CASE WHEN ' . Sql::col('elements.draftId') . " IS NOT NULL THEN $owner END) as " . Sql::col('drafts'))
            ->groupBy(["$cache.targetElementId", 'elements.type'])
            ->get();
        foreach ($typeRows as $row) {
            /** @var object{target: int|string, type: string, count: int|string, drafts: int|string} $row */
            // an element has one type, so the counts per type add up to the relating elements
            $data[(int)$row->target]['owners'] += (int)$row->count;
            if (is_subclass_of($row->type, ElementInterface::class)) {
                $data[(int)$row->target]['types'][$row->type] = ['count' => (int)$row->count, 'drafts' => (int)$row->drafts];
            }
        }
        foreach ($data as &$element) {
            uasort($element['types'], fn(array $a, array $b): int => $b['count'] <=> $a['count']);
        }
        unset($element);

        $globalRows = DB::table($cache)
            ->select(['targetElementId', 'targetSiteId', 'type', 'globalKey'])
            ->whereIntegerInRaw('targetElementId', $elementIds)
            ->whereNull('sourceElementId')
            ->where('targetSiteId', $siteId)
            ->get();
        foreach ($globalRows as $row) {
            /** @var object{targetElementId: int|string, targetSiteId: int|string, type: string, globalKey: string|null} $row */
            $data[(int)$row->targetElementId]['globalRows'][] = (array)$row;
        }

        // the first relating elements of each listed element (window function: PostgreSQL, MySQL 8)
        $sourceSite = Sql::col("$cache.sourceSiteId");
        $ranked = self::fastSubQueryFor($elementIds, $siteId)
            ->select(["$cache.targetElementId as target", "$cache.sourcePrimaryOwnerId as owner", 'elements.type as type'])
            ->selectRaw("MIN(CASE WHEN $sourceSite = $preferredSiteId THEN 0 ELSE $sourceSite END) as " . Sql::col('siteId'))
            ->selectRaw("ROW_NUMBER() OVER (PARTITION BY $target ORDER BY MAX(" . Sql::col('elements.dateUpdated') . ") DESC, $owner DESC) as " . Sql::col('pos'))
            ->groupBy(["$cache.targetElementId", "$cache.sourcePrimaryOwnerId", 'elements.type']);
        $first = DB::query()->fromSub($ranked, 'ranked')->where('pos', '<=', $limit)->orderBy('target')->orderBy('pos')->get();
        $idsByTypeAndSite = [];
        foreach ($first as $row) {
            /** @var object{target: int|string, owner: int|string, type: string, siteId: int|string, pos: int|string} $row */
            if (is_subclass_of($row->type, ElementInterface::class)) {
                $idsByTypeAndSite[$row->type][(int)$row->siteId ?: $preferredSiteId][] = (int)$row->owner;
            }
        }
        $loaded = [];
        foreach ($idsByTypeAndSite as $type => $idsBySite) {
            foreach ($idsBySite as $siteIdOfRows => $ids) {
                $query = ElementRelations::find($type)->id(array_values(array_unique($ids)))->siteId($siteIdOfRows)->status(null)
                    ->drafts(null)->provisionalDrafts(false)->revisions(false);
                foreach ($query->all() as $element) {
                    if ($element instanceof ElementInterface) {
                        $loaded[(int)$element->id] = $element;
                    }
                }
            }
        }
        foreach ($first as $row) {
            if (isset($loaded[(int)$row->owner])) {
                $data[(int)$row->target]['preview'][] = $loaded[(int)$row->owner];
            }
        }
        foreach (self::otherSiteCountsFor($elementIds, $siteId) as $id => $counts) {
            $data[$id]['otherSites'] = $counts;
        }
        foreach ($data as $id => $element) {
            self::$prefetched["$id:$siteId"] = $element;
        }
    }

    /**
     * One query per element type (and per drafts/no-drafts unless `$drafts` is given), all using the `usesElement` param.
     * With a query factory (withQueryFactory()) only the types it returns a query for, without drafts.
     * @param int|int[]|null $siteIds
     * @param string|string[]|null $sections
     * @param string|string[]|null $entryTypes
     * @param string|string[]|null $status null: every status; omitted: see DEFAULT
     * @param class-string<ElementInterface>[]|null $types restrict to these element types
     * @return list<ElementQuery<ElementInterface>>
     */
    public function getElementQueries(array|int|null $siteIds = null, array|string|null $sections = null, array|string|null $entryTypes = null, array|string|null $status = self::DEFAULT, bool|string|null $drafts = self::DEFAULT, ?array $types = null, ?string $search = null): array
    {
        $siteIds = $this->normalizeSiteIds($siteIds);
        if (is_string($sections)) $sections = [$sections];
        if (is_string($entryTypes)) $entryTypes = [$entryTypes];
        $factory = $this->queryFactory;
        // front-end templates: unless asked for, only what a visitor may see (live entries, enabled elements, no
        // drafts); the control panel and console keep "everything" as the default (see DEFAULT)
        $siteDefaults = !$factory && ElementRelations::isSiteRequest();
        $statusGiven = $status !== self::DEFAULT;
        $status = $statusGiven ? $status : null;
        $drafts = match (true) {
            (bool)$factory => false,
            is_bool($drafts) => $drafts,
            $drafts === null => null,
            default => $siteDefaults ? false : null,
        };

        $queries = [];
        foreach ($this->getElementTypes() as $elementType) {
            if ($types !== null && !in_array($elementType, $types, true)) {
                continue;
            }
            // a section or entry type names entries: no other type can match it
            if ((!empty($sections) || !empty($entryTypes)) && !is_a($elementType, Entry::class, true)) {
                continue;
            }
            // statuses are per type ("live" for entries and products, "enabled" for categories, "active" for users, …)
            // and Craft aborts a query asked for one its type doesn't know: give each type only the words it knows, and
            // skip it when none is left. Types without statuses (tags, global sets, addresses) only ever count as enabled.
            $statusForType = $status;
            if ($status !== null) {
                $statusForType = $elementType::hasStatuses()
                    ? array_values(array_intersect((array)$status, array_keys($elementType::statuses())))
                    : (in_array('enabled', (array)$status, true) ? null : []);
                if ($statusForType === []) {
                    continue;
                }
            }
            $query = $factory ? $factory($elementType) : ElementRelations::find($elementType);
            if (!$query instanceof ElementQuery) {
                continue;
            }
            /** @var ElementQuery<ElementInterface> $query */
            $query->usesElement($this->elementId)->usesElementSite($this->targetSiteId); // see Elements\Queries\UsesElement
            $query
                ->revisions(false)
                ->provisionalDrafts(false);
            // explicit statuses (null: all of them), or everything outside site requests; otherwise Craft's own
            // default (live/enabled, or the GraphQL resolver's)
            if ($factory ? $statusGiven : ($statusGiven || !$siteDefaults)) {
                $query->status($statusForType);
            }
            // the site of the relating element; `usesElement` matches the cache rows of that site. Several sites (or
            // all): each element once, in the first of them it uses the element in
            empty($siteIds) ? $query->site('*')->unique() : $query->siteId($siteIds)->unique();

            if ($search) {
                $query->search($search);
            }
            if ($query instanceof EntryQuery) {
                if (!empty($sections)) $query->section($sections);
                if (!empty($entryTypes)) $query->type($entryTypes);
            }

            if ($drafts === null) {
                // no draft of this type uses the element: its drafts query would come back empty
                if ($this->typeCounts()[$elementType]['drafts'] ?? 1) {
                    $queries[] = (clone $query)->drafts(true);
                }
                $queries[] = (clone $query)->drafts(false);
            } else {
                $queries[] = $query->drafts($drafts);
            }
        }
        return $queries;
    }

    /**
     * @param int|int[]|null $siteIds
     * @param string|string[]|null $sections
     * @param string|string[]|null $entryTypes
     * @param string|string[]|null $status null: every status; omitted: see DEFAULT
     * @param class-string<ElementInterface>[]|null $types
     * @return list<ElementInterface>
     */
    public function getElements(array|int|null $siteIds = null, ?int $limit = null, int $offset = 0, array|string|null $sections = null, array|string|null $entryTypes = null, array|string|null $status = self::DEFAULT, bool|string|null $drafts = self::DEFAULT, ?array $types = null, ?string $search = null): array
    {
        return iterator_to_array(
            $this->getElementsIterator($siteIds, $limit, $offset, 100, $sections, $entryTypes, $status, $drafts, $types, $search),
            false
        );
    }

    /**
     * Streams the related elements across all underlying queries, honouring a global limit/offset.
     * @param int|int[]|null $siteIds
     * @param string|string[]|null $sections
     * @param string|string[]|null $entryTypes
     * @param string|string[]|null $status null: every status; omitted: see DEFAULT
     * @param class-string<ElementInterface>[]|null $types
     * @return \Generator<int, ElementInterface>
     */
    public function getElementsIterator(array|int|null $siteIds = null, ?int $limit = null, int $offset = 0, int $batchSize = 100, array|string|null $sections = null, array|string|null $entryTypes = null, array|string|null $status = self::DEFAULT, bool|string|null $drafts = self::DEFAULT, ?array $types = null, ?string $search = null): \Generator
    {
        $remaining = $limit;

        foreach ($this->getElementQueries($siteIds, $sections, $entryTypes, $status, $drafts, $types, $search) as $query) {
            // counting only serves skipping whole queries for an offset (the first batch shows an empty query anyway)
            if ($offset > 0) {
                $total = $query->count();
                if ($offset >= $total) {
                    $offset -= $total;
                    continue;
                }
            }

            $localOffset = $offset;
            $offset = 0;

            while (true) {
                $take = $remaining === null ? $batchSize : min($batchSize, $remaining);
                /** @var list<ElementInterface> $batch */
                $batch = (clone $query)->offset($localOffset)->limit($take)->all();
                if (empty($batch)) {
                    break;
                }
                foreach ($batch as $element) {
                    yield $element;
                    if ($remaining !== null && --$remaining <= 0) {
                        return;
                    }
                }
                if (count($batch) < $take) {
                    break;
                }
                $localOffset += count($batch);
            }
        }
    }

    /**
     * @param int|int[]|null $siteIds
     * @return int[]
     */
    private function normalizeSiteIds(array|int|null $siteIds): array
    {
        if ($siteIds === null) return [$this->siteId];
        if (is_int($siteIds)) return [$siteIds];
        return $siteIds;
    }

    /** Cache rows pointing at this element, joined to their (live, non-revision, non-provisional) source elements. */
    private function fastSubQuery(): Builder
    {
        return self::fastSubQueryFor($this->elementId, $this->targetSiteId);
    }

    /**
     * fastSubQuery() for one or several elements (and only their versions in one site).
     * @param int|list<int> $elementIds
     */
    private static function fastSubQueryFor(int|array $elementIds, ?int $targetSiteId = null): Builder
    {
        $cache = CacheRecord::TABLE;
        return DB::table($cache)
            ->join(Table::ELEMENTS, 'elements.id', '=', "$cache.sourcePrimaryOwnerId")
            ->leftJoin(Table::DRAFTS, 'drafts.id', '=', 'elements.draftId')
            ->when(is_int($elementIds),
                fn(Builder $query) => $query->where("$cache.targetElementId", $elementIds),
                fn(Builder $query) => $query->whereIntegerInRaw("$cache.targetElementId", (array)$elementIds))
            ->when($targetSiteId !== null, fn(Builder $query) => $query->where("$cache.targetSiteId", $targetSiteId))
            ->whereNull('elements.dateDeleted')
            // elements of a type Craft no longer knows (a plugin removed or disabled) are listed nowhere, so not counted
            ->whereIn('elements.type', ElementRelations::elementTypes())
            ->where('elements.archived', false)
            ->whereNull('elements.revisionId')
            ->where(fn(Builder $query) => $query->whereNull('drafts.id')->orWhere('drafts.provisional', false));
    }

    /** @return list<array{siteId: int, type: string, count: int}> */
    private function getFastCountQueryResult(): array
    {
        if ($this->_fastCountQueryResult === null) {
            $cache = CacheRecord::TABLE;
            $subQuery = $this->fastSubQuery()
                ->select(["$cache.sourceSiteId as siteId", 'elements.type as type'])
                ->groupBy(["$cache.sourcePrimaryOwnerId", "$cache.sourceSiteId", 'elements.type']);

            $rows = DB::query()
                ->fromSub($subQuery, 'sub')
                ->select(['siteId', 'type'])
                ->selectRaw('COUNT(*) as ' . Sql::col('count'))
                ->groupBy(['siteId', 'type'])
                ->get();
            // MySQL returns numbers as strings, PostgreSQL as ints
            $this->_fastCountQueryResult = $rows->map(fn(object $row): array => [
                'siteId' => (int)$row->siteId,
                'type' => (string)$row->type,
                'count' => (int)$row->count,
            ])->values()->all();
        }
        return $this->_fastCountQueryResult;
    }
}
