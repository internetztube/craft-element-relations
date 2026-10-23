<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Elements\Queries;

use Closure;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Asset\Data\Volume;
use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Queries\ElementQuery;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Asset\Volumes;
use CraftCms\Cms\Plugin\Plugins;
use CraftCms\Cms\Section\Sections;
use CraftCms\Cms\Site\Sites;
use CraftCms\Cms\Support\Query;
use CraftCms\Cms\User\Elements\User;
use CraftCms\DependencyAwareCache\Dependency\TagDependency;
use CraftCms\DependencyAwareCache\Facades\DependencyCache;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Elements\RestrictedUsage;
use Internetztube\ElementRelations\Elements\Usage;
use Internetztube\ElementRelations\Models\CacheRecord;
use Override;

/**
 * Element query over all element types at once. Combine with the `usesElement` param:
 *
 * ```php
 * Usage::find()->usesElement($asset)->search('contact')->all(); // Entry, Product, User, ... instances
 * ```
 *
 * Filtering, searching, sorting, counting and paging run on the `elements` table across all types. The matched rows
 * are then loaded through their own element type's query (one query per type and site on the page), so every result
 * is a fully populated element of its real class.
 *
 * The tables of the types a list can contain (entries, assets, users, Commerce products) are left-joined, so Craft's
 * own sort options and filter rules of those types work on it unchanged: they write their conditions against those
 * tables (`entries.sectionId`, `assets.filename`, …), which rows of other types never match.
 *
 * @extends ElementQuery<ElementInterface>
 */
class UsageQuery extends ElementQuery
{
    /** @var int|string|ElementInterface|array<int|string|ElementInterface>|null the element(s) whose usages to list (ids arrive as strings from element index criteria) */
    public mixed $usesElement = null;

    /** @var int|string|array<int|string>|null with `usesElement`: only relations to the target in this site (or these sites) */
    public mixed $usesElementSite = null;

    /**
     * Restrict to these element type classes (the index's type dropdown sends this as criteria).
     * @var class-string<ElementInterface>|class-string<ElementInterface>[]|null
     */
    public mixed $elementTypes = null;

    /**
     * List every site at once, overriding the `siteId` Craft's element index always sends (its site menu/current site).
     * The field's index sets this so a "where is it used?" list covers all sites, with the Site column telling which.
     */
    public bool $allSites = false;

    /** With `allSites`: only these of the sites (the field's site dropdown); null = every site. */
    public mixed $inSites = null;

    /**
     * With `allSites`: the site the index asked for before it was widened, so an element used in several sites is
     * shown in that one when it is among them (UsesElement picks one row per element).
     */
    public ?int $preferredSiteId = null;

    /**
     * Craft's own query params for the element types a list can contain, so templates and PHP can narrow a list like
     * Craft's own queries (`craft.usages().usesElement(x).sectionId(3)`); the stock filter rules write their conditions
     * on the joined tables themselves. Each applies to its type's table; rows of other types never match. Values as
     * Craft's queries take them (ids, `['not', …]`, `'*.pdf'`, `'>= 2026-01-01'`, a DateTime).
     */
    public mixed $sectionId = null;
    public mixed $section = null;
    public mixed $typeId = null;
    public mixed $authorId = null;
    public mixed $postDate = null;
    public mixed $expiryDate = null;
    public mixed $volumeId = null;
    public mixed $filename = null;
    public mixed $kind = null;
    public mixed $size = null;
    public mixed $width = null;
    public mixed $height = null;
    public mixed $dateModified = null;
    public mixed $email = null;
    public mixed $username = null;
    public mixed $firstName = null;
    public mixed $lastName = null;
    public mixed $lastLoginDate = null;

    /**
     * Only what this user may view. Applied automatically for non-admin users in control panel requests (the field's
     * list, the wizard's modal, any element index request for this type); `false` switches it off (server code only).
     *
     * Private on purpose: element index requests apply request criteria to the query with Typecast::configure(), so
     * a public property could be switched off from the browser. Only viewableBy() sets it.
     *
     * Restricted queries need `usesElement` targets the user may view, and then only return relating elements that
     * passed Craft's view permission for that user (see viewablePairs()), so search, sorting and counts never touch
     * anything else. hiddenCount() tells how many usages were left out.
     */
    private User|false|null $viewableBy = null;

    /** Cache tag of the per-user permission results (viewablePairs()). */
    public const PERMISSION_CACHE_TAG = 'element-relations:usage-permissions';

    /** @var array<string, array{pairs: array<int, list<int>>}> per request: "<viewer>|<targets>" => viewable pairs */
    private static array $pairs = [];

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        parent::__construct(Usage::class, $config);
        // before Craft's own callbacks: they apply `siteId`, which allSites widens
        array_unshift($this->beforeQueryCallbacks, static function (ElementQuery $query): void {
            if ($query instanceof self) {
                $query->prepareUsages();
            }
        });
    }

    /** @param class-string<ElementInterface>|class-string<ElementInterface>[]|null $value */
    public function elementTypes(mixed $value): static
    {
        $this->elementTypes = $value;
        return $this;
    }

    /** @param int|string|ElementInterface|array<int|string|ElementInterface>|null $value */
    public function usesElement(mixed $value): static
    {
        $this->usesElement = $value;
        return $this;
    }

    /** @param int|string|array<int|string>|null $value */
    public function usesElementSite(mixed $value): static
    {
        $this->usesElementSite = $value;
        return $this;
    }

    public function viewableBy(User|false|null $user): static
    {
        $this->viewableBy = $user;
        return $this;
    }

    public function allSites(bool $value = true): static
    {
        $this->allSites = $value;
        return $this;
    }

    public function sectionId(mixed $value): static { $this->sectionId = $value; return $this; }
    public function section(mixed $value): static { $this->section = $value; return $this; }
    public function typeId(mixed $value): static { $this->typeId = $value; return $this; }
    public function authorId(mixed $value): static { $this->authorId = $value; return $this; }
    public function postDate(mixed $value): static { $this->postDate = $value; return $this; }
    public function expiryDate(mixed $value): static { $this->expiryDate = $value; return $this; }
    public function volumeId(mixed $value): static { $this->volumeId = $value; return $this; }
    public function filename(mixed $value): static { $this->filename = $value; return $this; }
    public function kind(mixed $value): static { $this->kind = $value; return $this; }
    public function size(mixed $value): static { $this->size = $value; return $this; }
    public function width(mixed $value): static { $this->width = $value; return $this; }
    public function height(mixed $value): static { $this->height = $value; return $this; }
    public function dateModified(mixed $value): static { $this->dateModified = $value; return $this; }
    public function email(mixed $value): static { $this->email = $value; return $this; }
    public function username(mixed $value): static { $this->username = $value; return $this; }
    public function firstName(mixed $value): static { $this->firstName = $value; return $this; }
    public function lastName(mixed $value): static { $this->lastName = $value; return $this; }
    public function lastLoginDate(mixed $value): static { $this->lastLoginDate = $value; return $this; }

    /** @param int|int[]|string|null $value site id(s), see $inSites */
    public function inSites(mixed $value): static
    {
        $this->inSites = $value;
        return $this;
    }

    /**
     * How many usages of the targets (relating element × site, drafts included; or only those with `$status`, e.g.
     * the live ones the delete dialog lists) the user may not view.
     * @param list<int> $targetIds
     * @param int|null $targetSiteId only usages of the targets in this site (the field's list); null: every site
     */
    public static function hiddenCount(array $targetIds, User $viewer, ?string $status = null, ?int $targetSiteId = null): int
    {
        if ($viewer->admin || !$targetIds) {
            return 0;
        }
        // the very query the list runs, once without and once with the restriction, so the numbers always add up
        $count = fn(User|false $as): int => Usage::find()->allSites()->status($status)->drafts($status === null ? null : false)
            ->viewableBy($as)->usesElement($targetIds)->usesElementSite($targetSiteId)->count();
        return max(0, $count(false) - $count($viewer));
    }

    /** The plugin's part of the query: sites, the types' tables, the targets, the viewer's permissions. */
    private function prepareUsages(): void
    {
        if ($this->allSites) {
            $sites = app(Sites::class);
            $this->preferredSiteId ??= is_numeric($this->siteId) ? (int)$this->siteId : (int)$sites->getCurrentSite()->id;
            $all = $sites->getAllSiteIds()->all();
            $only = array_map(fn(int|float|string $id): int => (int)$id, array_filter((array)$this->inSites, is_numeric(...)));
            $this->siteId = $this->inSites !== null && $this->inSites !== '' ? (array_values(array_intersect($all, $only)) ?: [0]) : $all;
        }
        $builder = $this->getQuery();
        // elements of a type Craft no longer knows (a plugin removed or disabled) cannot be shown, so they are not counted
        $builder->whereIn('elements.type', ElementRelations::elementTypes());
        if ($this->elementTypes) {
            $builder->whereIn('elements.type', (array)$this->elementTypes);
        }
        foreach (self::typeTables() as $table) {
            $builder->leftJoin($table, "$table.id", '=', 'elements.id');
        }
        $this->applyStockParams($builder);
        UsesElement::apply($this, UsesElement::params($this));
        $viewer = $this->viewer();
        if (!$viewer) {
            return;
        }
        // a restricted user only ever sees usages of elements they may view, and only usages they may view
        $targetIds = $this->targetIds();
        foreach ($targetIds as $targetId) {
            $target = ElementRelations::elementById($targetId, '*');
            if (!$target || !$target->canView($viewer)) {
                $builder->whereRaw('0 = 1');
                return;
            }
        }
        $pairs = $targetIds ? self::viewablePairs($targetIds, $viewer)['pairs'] : [];
        if (!$pairs) {
            $builder->whereRaw('0 = 1');
            return;
        }
        $builder->where(function (Builder $condition) use ($pairs): void {
            foreach ($pairs as $siteId => $ids) {
                $condition->orWhere(fn(Builder $pair) => $pair->where('elements_sites.siteId', $siteId)->whereIntegerInRaw('elements.id', $ids));
            }
        });
    }

    /**
     * The stock params on their tables. A type's param narrows the list to that type, like on Craft's own queries (an
     * asset never has a section): "expiry date is empty" means entries without one, not every asset.
     */
    private function applyStockParams(Builder $builder): void
    {
        if ($this->section === '*') {
            $builder->whereNotNull('entries.sectionId');
        }
        $ofType = fn(string $column): Builder => $builder->whereNotNull(strtok($column, '.') . '.id');
        $numeric = ['entries.sectionId' => $this->sectionId, 'entries.typeId' => $this->typeId, 'assets.volumeId' => $this->volumeId, 'assets.size' => $this->size, 'assets.width' => $this->width, 'assets.height' => $this->height];
        foreach (array_filter(array_map(self::param(...), $numeric)) as $column => $value) {
            Query::whereNumericParam($builder, $column, $value);
            $ofType($column);
        }
        $dates = ['entries.postDate' => $this->postDate, 'entries.expiryDate' => $this->expiryDate, 'assets.dateModified' => $this->dateModified, 'users.lastLoginDate' => $this->lastLoginDate];
        foreach (array_filter(array_map(fn(mixed $value): \DateTimeInterface|array|string|null => $value instanceof \DateTimeInterface ? $value : self::param($value), $dates)) as $column => $value) {
            Query::whereDateParam($builder, $column, $value);
            $ofType($column);
        }
        $texts = ['assets.filename' => $this->filename, 'assets.kind' => $this->kind, 'users.email' => $this->email, 'users.username' => $this->username, 'users.firstName' => $this->firstName, 'users.lastName' => $this->lastName];
        foreach (array_filter(array_map(self::param(...), $texts)) as $column => $value) {
            Query::whereParam($builder, $column, $value, caseInsensitive: true);
            $ofType($column);
        }
        if (($authorId = self::param($this->authorId)) !== null) {
            $builder->whereExists(fn(Builder $authors) => Query::whereNumericParam(
                $authors->from(Table::ENTRIES_AUTHORS)->whereColumn('entries_authors.entryId', 'elements.id'),
                'entries_authors.authorId',
                $authorId,
            ));
        }
    }

    /**
     * A param value as Craft parses it: a string, or a list of strings (ids, `['not', 3, 4]`, `'*.pdf'`); null when not set.
     * @return list<string>|string|null
     */
    private static function param(mixed $value): array|string|null
    {
        if (is_array($value)) {
            return array_values(array_map(fn(mixed $item): string => is_scalar($item) ? (string)$item : '', $value));
        }
        return is_scalar($value) ? (string)$value : null;
    }

    /**
     * The tables of the element types a list can contain, joined so their columns resolve (see the class docs).
     * @return list<string>
     */
    private static function typeTables(): array
    {
        return [Table::ENTRIES, Table::ASSETS, Table::USERS, ...(self::hasProducts() ? ['commerce_products'] : [])];
    }

    /**
     * Entry statuses, applied to every element type: an entry or product is live/pending/expired by its dates, every
     * other enabled element counts as live.
     */
    #[Override]
    protected function statusCondition(string $status): Closure
    {
        if (!in_array($status, [Entry::STATUS_LIVE, Entry::STATUS_PENDING, Entry::STATUS_EXPIRED], true)) {
            return parent::statusCondition($status);
        }
        $now = now()->endOfMinute()->setTimezone('UTC'); // like EntryQuery: cacheable per minute
        $dated = self::hasProducts() ? [Table::ENTRIES, 'commerce_products'] : [Table::ENTRIES];
        $enabled = fn(Builder $query): Builder => $query->whereBool('elements.enabled', true)->whereBool('elements_sites.enabled', true);
        return match ($status) {
            Entry::STATUS_LIVE => function (Builder $query) use ($enabled, $dated, $now): Builder {
                $enabled($query);
                foreach ($dated as $table) {
                    $query->where(fn(Builder $live) => $live
                        ->whereNull("$table.id") // not an element of this dated type
                        ->orWhere(fn(Builder $byDate) => $byDate
                            ->where("$table.postDate", '<=', $now)
                            ->where(fn(Builder $expiry) => $expiry->whereNull("$table.expiryDate")->orWhere("$table.expiryDate", '>', $now))));
                }
                return $query;
            },
            Entry::STATUS_PENDING => fn(Builder $query): Builder => $enabled($query)->where(function (Builder $pending) use ($dated, $now): void {
                foreach ($dated as $table) {
                    $pending->orWhere("$table.postDate", '>', $now);
                }
            }),
            default => fn(Builder $query): Builder => $enabled($query)->where(function (Builder $expired) use ($dated, $now): void {
                foreach ($dated as $table) {
                    $expired->orWhere("$table.expiryDate", '<=', $now);
                }
            }),
        };
    }

    /** @return list<int> the `usesElement` targets */
    private function targetIds(): array
    {
        if ($this->usesElement === null) {
            return [];
        }
        return array_values(array_filter(array_map(
            fn(mixed $target): int => $target instanceof ElementInterface ? (int)$target->id : (int)(is_numeric($target) ? $target : 0),
            is_array($this->usesElement) ? $this->usesElement : [$this->usesElement],
        )));
    }

    /**
     * The usages (relating element id per site) of the targets that the user may view: candidates from the cache,
     * narrowed by the coarse SQL permissions, then each checked with Craft's view permission. Memoized per request.
     * @param list<int> $targetIds
     * @return array{pairs: array<int, list<int>>} siteId => viewable element ids
     */
    private static function viewablePairs(array $targetIds, User $viewer): array
    {
        sort($targetIds);
        $key = $viewer->id . '|' . implode(',', $targetIds);
        // an element with thousands of usages means thousands of permission checks: kept in the data cache until
        // anything that could change the answer happens (see resetPermissionCache())
        /** @var array{pairs: array<int, list<int>>} */
        return self::$pairs[$key] ??= DependencyCache::remember(
            'element-relations:viewable:' . md5($key),
            600, // a safety net for what no event reports (a plugin's view rule that depends on something else)
            fn(): array => self::computeViewablePairs($targetIds, $viewer),
            new TagDependency([self::PERMISSION_CACHE_TAG]),
        );
    }

    /**
     * @param list<int> $targetIds
     * @return array{pairs: array<int, list<int>>}
     */
    private static function computeViewablePairs(array $targetIds, User $viewer): array
    {
        $cache = CacheRecord::TABLE;
        $candidates = DB::table($cache)
            ->select(['elements.id as id', 'elements_sites.siteId as siteId', 'elements.type as type'])
            ->distinct()
            ->join(Table::ELEMENTS, 'elements.id', '=', "$cache.sourcePrimaryOwnerId")
            ->join(Table::ELEMENTS_SITES, fn($join) => $join->on('elements_sites.elementId', '=', 'elements.id')->on('elements_sites.siteId', '=', "$cache.sourceSiteId"))
            ->leftJoin(Table::ENTRIES, 'entries.id', '=', 'elements.id')
            ->leftJoin(Table::ASSETS, 'assets.id', '=', 'elements.id')
            ->whereIn("$cache.targetElementId", $targetIds)
            ->whereNull('elements.dateDeleted')
            ->whereNull('elements.revisionId')
            ->whereNotNull("$cache.sourceElementId")
            ->whereIn('elements.type', ElementRelations::elementTypes()) // like prepareUsages()
            ->where(fn(Builder $query) => self::viewCondition($query, $viewer))
            ->get();

        $byTypeAndSite = [];
        foreach ($candidates as $row) {
            /** @var object{id: int|string, siteId: int|string, type: string} $row */
            $byTypeAndSite[$row->type][(int)$row->siteId][] = (int)$row->id;
        }
        $pairs = [];
        /** @var array<class-string<ElementInterface>, array<int, list<int>>> $byTypeAndSite registered types only (see the query) */
        foreach ($byTypeAndSite as $type => $sites) {
            foreach ($sites as $siteId => $ids) {
                foreach (array_chunk($ids, 500) as $chunk) { // bounded memory for thousands of usages
                    foreach (ElementRelations::find($type)->id($chunk)->siteId($siteId)->status(null)->drafts(null)->provisionalDrafts(null)->all() as $element) {
                        if ($element instanceof ElementInterface && $element->canView($viewer)) {
                            $pairs[$siteId][] = (int)$element->id;
                        }
                    }
                }
            }
        }
        return ['pairs' => $pairs];
    }

    /**
     * Forgets the permission results, in this request and in the data cache. Called whenever the answer could change:
     * relations written, any element saved, deleted or restored, users assigned to groups, project config changed
     * (sections, volumes, group permissions); see ElementRelations::boot().
     */
    public static function resetPermissionCache(): void
    {
        self::$pairs = [];
        TagDependency::invalidate(self::PERMISSION_CACHE_TAG);
    }

    /** The user to restrict to: explicit, or the logged-in non-admin user of a control panel request. */
    private function viewer(): ?User
    {
        if ($this->viewableBy instanceof User) {
            return $this->viewableBy->admin ? null : $this->viewableBy;
        }
        if ($this->viewableBy === false) {
            return null;
        }
        return ElementRelations::restrictedViewer();
    }

    /**
     * The coarse part of Craft's view permissions as SQL, for the core element types: only what the user could see
     * at all (section, volume, other users, sites). Finer rules (other authors' entries, drafts, temporary uploads,
     * plugin authorization rules) are checked per element in computeViewablePairs().
     */
    private static function viewCondition(Builder $query, User $user): void
    {
        // ids of the sections/volumes whose permission (`viewEntries:<uid>`, ...) the user has
        $sectionIds = app(Sections::class)->getAllSections()->filter(fn(Section $section): bool => $user->can("viewEntries:$section->uid"))->map(fn(Section $section): int => (int)$section->id)->values()->all();
        $volumeIds = app(Volumes::class)->getAllVolumes()->filter(fn(Volume $volume): bool => $user->can("viewAssets:$volume->uid") || $user->can("viewPeerAssets:$volume->uid"))->map(fn(Volume $volume): int => (int)$volume->id)->values()->all();
        $core = [Entry::class, Asset::class, User::class];
        $query->where(function (Builder $types) use ($core, $sectionIds, $volumeIds, $user): void {
            $types->whereNotIn('elements.type', $core) // plugin types: checked per element
                ->orWhere(fn(Builder $entries) => $entries->where('elements.type', Entry::class)->whereIn('entries.sectionId', $sectionIds ?: [0]))
                ->orWhere(fn(Builder $assets) => $assets->where('elements.type', Asset::class)->whereIn('assets.volumeId', $volumeIds ?: [0]))
                ->orWhere(fn(Builder $users) => $user->can('viewUsers')
                    ? $users->where('elements.type', User::class)
                    : $users->where('elements.type', User::class)->where('elements.id', $user->id));
        });
        if (app(Sites::class)->isMultiSite()) {
            $siteIds = app(Sites::class)->getAllSites()->filter(fn(Site $site): bool => $user->can("editSite:$site->uid"))->map(fn(Site $site): int => (int)$site->id)->values()->all();
            $query->whereIn('elements_sites.siteId', $siteIds ?: [0]);
        }
    }

    private static function hasProducts(): bool
    {
        return app(Plugins::class)->isPluginEnabled('commerce');
    }

    /**
     * Each row loaded through its own element type's query (one per type and site), in this query's order (sorting,
     * pagination). The stand-in for an element the viewer may not view is a last line of defence only: restricted
     * queries already contain nothing but viewable elements (see prepareUsages()).
     * @param array<int, \stdClass> $items
     * @return array<int, ElementInterface|array<string, mixed>>
     */
    #[Override]
    public function hydrate(array $items): array
    {
        if ($this->asArray) {
            return parent::hydrate($items);
        }
        $rows = array_values(array_map(fn(\stdClass $row): array => ['id' => (int)$row->id, 'siteId' => (int)$row->siteId], $items));
        // the element type of each row (not selected by the base query, which Craft's search reuses as a subquery)
        /** @var array<int, string> $types */
        $types = $rows ? DB::table(Table::ELEMENTS)->whereIntegerInRaw('id', array_column($rows, 'id'))->pluck('type', 'id')->all() : [];

        $idsByTypeAndSite = [];
        foreach ($rows as $row) {
            $idsByTypeAndSite[$types[$row['id']] ?? ''][$row['siteId']][] = $row['id'];
        }
        unset($idsByTypeAndSite['']); // hard-deleted since the query ran

        $elements = [];
        foreach ($idsByTypeAndSite as $type => $sites) {
            if (!is_subclass_of($type, ElementInterface::class)) {
                continue;
            }
            foreach ($sites as $siteId => $ids) {
                $loaded = ElementRelations::find($type)->id($ids)->siteId($siteId)->status(null)->drafts(null)
                    ->provisionalDrafts(null)->revisions(null)->trashed(null)->all();
                foreach ($loaded as $element) {
                    if ($element instanceof ElementInterface) {
                        $elements["$type:$siteId:$element->id"] = $element;
                        Usage::$via[(int)$element->id] = []; // reset: an earlier list in this request may have used another target
                    }
                }
            }
        }
        $this->collectVia($rows, $elements);

        $viewer = $this->viewer();
        $ordered = [];
        foreach ($rows as $row) {
            $key = ($types[$row['id']] ?? '') . ":{$row['siteId']}:{$row['id']}";
            if (isset($elements[$key])) {
                $ordered[] = $viewer && !$elements[$key]->canView($viewer) ? RestrictedUsage::fromElement($elements[$key]) : $elements[$key];
            }
        }
        return $ordered;
    }

    /**
     * Through which field(s) each loaded element uses the target (the index's Field column), with the state of the
     * element holding the relation: a disabled Matrix block (nested entry) of a live owner is no live usage, which the
     * column says. Also the sites it uses the target in (the Site column).
     * @param list<array{id: int, siteId: int}> $rows
     * @param array<string, ElementInterface> $elements
     */
    private function collectVia(array $rows, array $elements): void
    {
        $targetIds = $this->targetIds();
        if (!$targetIds || !$elements) {
            return;
        }
        $cache = CacheRecord::TABLE;
        $targetSiteIds = UsesElement::targetSiteIds($this->usesElementSite);
        $sourceSites = array_values(array_unique(array_column($rows, 'siteId')));
        $rowsVia = DB::table($cache)
            ->select(["$cache.sourcePrimaryOwnerId", "$cache.sourceSiteId", "$cache.sourceElementId", "$cache.fieldId", "$cache.type", 'sources.enabled as enabled', 'sources_sites.enabled as enabledForSite'])
            ->distinct()
            ->leftJoin(Table::ELEMENTS . ' as sources', 'sources.id', '=', "$cache.sourceElementId")
            ->leftJoin(Table::ELEMENTS_SITES . ' as sources_sites', fn($join) => $join->on('sources_sites.elementId', '=', "$cache.sourceElementId")->on('sources_sites.siteId', '=', "$cache.sourceSiteId"))
            ->whereIn("$cache.targetElementId", $targetIds)
            ->whereIntegerInRaw("$cache.sourcePrimaryOwnerId", array_column($rows, 'id'))
            ->when($targetSiteIds !== null, fn(Builder $query) => $query->whereIn("$cache.targetSiteId", (array)$targetSiteIds))
            // a list spanning every site names the fields and sites of all of them; one site's list, that site's
            ->when(!$this->allSites, fn(Builder $query) => $query->whereIn("$cache.sourceSiteId", $sourceSites))
            ->get();
        Usage::$sites = [];
        foreach ($rowsVia as $via) {
            /** @var object{sourcePrimaryOwnerId: int|string, sourceSiteId: int|string, sourceElementId: int|string|null, fieldId: int|string|null, type: string, enabled: bool|int|string|null, enabledForSite: bool|int|string|null} $via */
            Usage::$sites[(int)$via->sourcePrimaryOwnerId][(int)$via->sourceSiteId] = true;
            $nested = $via->sourceElementId !== null && (int)$via->sourceElementId !== (int)$via->sourcePrimaryOwnerId;
            $disabled = $nested && (($via->enabled !== null && !(bool)$via->enabled) || ($via->enabledForSite !== null && !(bool)$via->enabledForSite));
            Usage::$via[(int)$via->sourcePrimaryOwnerId][] = [
                'fieldId' => $via->fieldId ? (int)$via->fieldId : null,
                'type' => $via->type,
                'disabledIn' => $disabled ? (int)$via->sourceElementId : null,
            ];
        }
    }
}
