<?php

declare(strict_types=1);

namespace internetztube\elementRelations\elements\db;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\User;
use craft\helpers\Db;
use craft\models\CategoryGroup;
use craft\models\Section;
use craft\models\Site;
use craft\models\Volume;
use DateTime;
use internetztube\elementRelations\behaviors\ElementQueryBehavior;
use internetztube\elementRelations\elements\RestrictedUsage;
use internetztube\elementRelations\elements\Usage;

/**
 * Element query over all element types at once. Combine with the `usesElement` param:
 *
 * ```php
 * Usage::find()->usesElement($asset)->search('contact')->all(); // Entry, Product, Menu, ... instances
 * ```
 *
 * Filtering, searching, sorting, counting and paging run on the `elements` table across all types. The matched rows
 * are then loaded through their own element type's query (one query per type and site on the page), so every result
 * is a fully populated element of its real class.
 *
 * @extends ElementQuery<int, ElementInterface>
 */
class UsageQuery extends ElementQuery
{
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
     * shown in that one when it is among them (ElementQueryBehavior picks one row per element).
     */
    public ?int $preferredSiteId = null;

    /**
     * Craft's own query params for the element types a list can contain, so the stock filter rules (Section, Entry
     * Type, Author, Post Date, Volume, Filename, File Type, Group, Email, …; see conditions/UsageCondition) and sort
     * options work on it. Each applies to its type's table (left-joined, see beforePrepare()); rows of other types
     * never match. Values as Craft's queries take them (ids, `['not', …]`, `'*.pdf'`, `'>= 2026-01-01'`).
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
    /** A category or tag group id (they are numbered separately; the filter rules use the two below). */
    public mixed $groupId = null;
    public mixed $categoryGroupId = null;
    public mixed $tagGroupId = null;
    public mixed $email = null;
    public mixed $username = null;
    public mixed $firstName = null;
    public mixed $lastName = null;
    public mixed $lastLoginDate = null;

    /** Which tables the stock params and sort options need: joined into Craft's inner and outer query. */
    private const TYPE_TABLES = ['assets' => Table::ASSETS, 'categories' => Table::CATEGORIES, 'tags' => Table::TAGS, 'users' => Table::USERS];

    /**
     * Only what this user may view. Applied automatically for non-admin users in control panel requests (the field's
     * list, the wizard's modal, any element index request for this type); `false` switches it off (server code only).
     *
     * Private on purpose: element index requests apply request criteria to the query with Craft::configure(), so a
     * public property could be switched off from the browser. Only viewableBy() sets it.
     *
     * Restricted queries need `usesElement` targets the user may view, and then only return relating elements that
     * passed Craft's Elements::canView() for that user (see viewablePairs()), so search, sorting and counts never touch
     * anything else. hiddenCount() tells how many usages were left out.
     */
    private User|false|null $_viewableBy = null;

    /** Cache tag of the per-user permission results (viewablePairs()). */
    public const PERMISSION_CACHE_TAG = 'element-relations:usage-permissions';

    /** @var array<string, array{pairs: array<int, int[]>}> per request: "<viewer>|<targets>" => viewable pairs */
    private static array $_pairs = [];

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        parent::__construct(Usage::class, $config);
    }

    /**
     * @param class-string<ElementInterface>|class-string<ElementInterface>[]|null $value
     */
    public function elementTypes(mixed $value): static
    {
        $this->elementTypes = $value;
        return $this;
    }

    public function viewableBy(User|false|null $user): static
    {
        $this->_viewableBy = $user;
        return $this;
    }

    /**
     * How many usages of the targets (relating element × site, drafts included) the user may not view.
     * @param int[] $targetIds
     */
    public static function hiddenCount(array $targetIds, User $viewer): int
    {
        if ($viewer->admin || !$targetIds) {
            return 0;
        }
        // the very query the list runs, once without and once with the restriction, so the numbers always add up
        $count = function (User|false $as) use ($targetIds): int {
            $query = Usage::find()->allSites()->status(null)->drafts(null)->viewableBy($as);
            Craft::configure($query, ['usesElement' => $targetIds]);
            return (int)$query->count();
        };
        return max(0, $count(false) - $count($viewer));
    }

    public function allSites(bool $value = true): static
    {
        $this->allSites = $value;
        return $this;
    }

    // the stock params as fluent setters, the way Craft's filter rules call them
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
    public function groupId(mixed $value): static { $this->groupId = $value; return $this; }
    public function categoryGroupId(mixed $value): static { $this->categoryGroupId = $value; return $this; }
    public function tagGroupId(mixed $value): static { $this->tagGroupId = $value; return $this; }
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

    protected function beforePrepare(): bool
    {
        if ($this->allSites) {
            $this->preferredSiteId ??= is_numeric($this->siteId) ? (int)$this->siteId : (int)Craft::$app->getSites()->getCurrentSite()->id;
            $all = Craft::$app->getSites()->getAllSiteIds();
            $only = array_map(intval(...), array_filter((array)$this->inSites, is_numeric(...)));
            // siteId is normalized before beforePrepare()
            $this->siteId = $this->inSites !== null && $this->inSites !== '' ? array_values(array_intersect($all, $only)) ?: [0] : $all;
        }
        /** @var Query<array-key, mixed> $subQuery prepare() creates it before calling beforePrepare() */
        $subQuery = $this->subQuery;
        // elements of a type Craft no longer knows (a plugin removed or disabled) cannot be shown, so they are not counted
        $subQuery->andWhere(['elements.type' => Craft::$app->getElements()->getAllElementTypes()]);
        $viewer = $this->viewer();
        if ($this->elementTypes) {
            $subQuery->andWhere(['elements.type' => (array)$this->elementTypes]);
        }
        // Date-based statuses (live / pending / expired) live in the entries and products tables. Left joins, so other
        // element types stay in the result (a plain joinElementTable() would inner-join and also drop Craft's type filter).
        $subQuery->leftJoin(['entries' => Table::ENTRIES], '[[entries.id]] = [[elements.id]]');
        if (self::hasProducts()) {
            $subQuery->leftJoin(['commerce_products' => '{{%commerce_products}}'], '[[commerce_products.id]] = [[elements.id]]');
        }
        // the types' tables in both of Craft's queries, so the stock sort options (`entries.sectionId`, `postDate`,
        // `assets.filename`, …) and the stock params resolve, like in Craft's own queries
        $outer = $this->query;
        $outer?->leftJoin(['entries' => Table::ENTRIES], '[[entries.id]] = [[elements.id]]');
        foreach (self::TYPE_TABLES as $alias => $table) {
            $subQuery->leftJoin([$alias => $table], "[[$alias.id]] = [[elements.id]]");
            $outer?->leftJoin([$alias => $table], "[[$alias.id]] = [[elements.id]]");
        }
        $this->applyStockParams($subQuery);
        if ($viewer) {
            // a restricted user only ever sees usages of elements they may view, and only usages they may view
            $targetIds = $this->targetIds();
            if (!$targetIds) {
                return false;
            }
            $elementsService = Craft::$app->getElements();
            foreach ($targetIds as $targetId) {
                $target = $elementsService->getElementById($targetId, null, '*');
                if (!$target || !$elementsService->canView($target, $viewer)) {
                    return false;
                }
            }
            $pairs = self::viewablePairs($targetIds, $viewer)['pairs'];
            if (!$pairs) {
                return false;
            }
            $condition = ['or'];
            foreach ($pairs as $siteId => $ids) {
                $condition[] = ['and', ['elements_sites.siteId' => $siteId], ['elements.id' => $ids]];
            }
            $subQuery->andWhere($condition);
        }
        return parent::beforePrepare();
    }

    /**
     * The stock params on their tables; a param of a type not in the list makes the query empty, like on Craft's own
     * queries (an asset never has a section).
     * @param Query<array-key, mixed> $subQuery
     */
    private function applyStockParams(Query $subQuery): void
    {
        if ($this->section === '*') {
            $subQuery->andWhere(['not', ['entries.sectionId' => null]]);
        }
        // a type's param narrows the list to that type: "expiry date is empty" means entries without one, not every
        // asset (which has no expiry date at all); the column's table alias says the type
        $where = function (array|string|null $condition, ?string $column = null) use ($subQuery): void {
            if ($condition) {
                $subQuery->andWhere($condition);
                if ($column !== null) {
                    $subQuery->andWhere(['not', [strtok($column, '.') . '.id' => null]]);
                }
            }
        };
        foreach ([
            'entries.sectionId' => $this->sectionId, 'entries.typeId' => $this->typeId,
            'assets.volumeId' => $this->volumeId, 'assets.size' => $this->size, 'assets.width' => $this->width, 'assets.height' => $this->height,
        ] as $column => $value) {
            if (($value = self::param($value)) !== null) {
                $where(Db::parseNumericParam($column, $value), $column);
            }
        }
        foreach (['entries.postDate' => $this->postDate, 'entries.expiryDate' => $this->expiryDate, 'assets.dateModified' => $this->dateModified, 'users.lastLoginDate' => $this->lastLoginDate] as $column => $value) {
            if ($value instanceof \DateTimeInterface) {
                $where(Db::parseDateParam($column, $value), $column);
            } elseif (($value = self::param($value)) !== null) {
                $where(Db::parseDateParam($column, $value), $column);
            }
        }
        foreach (['assets.filename' => $this->filename, 'assets.kind' => $this->kind, 'users.email' => $this->email, 'users.username' => $this->username, 'users.firstName' => $this->firstName, 'users.lastName' => $this->lastName] as $column => $value) {
            if (($value = self::param($value)) !== null) {
                $where(Db::parseParam($column, $value, '=', true), $column);
            }
        }
        $inGroup = fn(string $table, string|array $groupId): array => ['and', ['not', ["$table.id" => null]], Db::parseNumericParam("$table.groupId", $groupId) ?: '0=1'];
        if (($groupId = self::param($this->groupId)) !== null) {
            $where(['or', $inGroup('categories', $groupId), $inGroup('tags', $groupId)]);
        }
        if (($groupId = self::param($this->categoryGroupId)) !== null) {
            $where($inGroup('categories', $groupId));
        }
        if (($groupId = self::param($this->tagGroupId)) !== null) {
            $where($inGroup('tags', $groupId));
        }
        if (($authorId = self::param($this->authorId)) !== null) {
            $where(['exists', (new Query())
                ->from(['entries_authors' => Table::ENTRIES_AUTHORS])
                ->where('[[entries_authors.entryId]] = [[elements.id]]')
                ->andWhere(Db::parseNumericParam('entries_authors.authorId', $authorId) ?: '0=1'),
            ]);
        }
    }

    /**
     * A param value as Craft's parsers take it: a string, or a list of strings (ids, `['not', 3, 4]`, `'*.pdf'`).
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
     * Entry statuses, applied to every element type: an entry or product is live/pending/expired by its dates, every
     * other enabled element counts as live.
     * @return string|array<int|string, mixed>|\yii\db\ExpressionInterface|false|null
     */
    protected function statusCondition(string $status): mixed
    {
        if (!in_array($status, [Entry::STATUS_LIVE, Entry::STATUS_PENDING, Entry::STATUS_EXPIRED], true)) {
            return parent::statusCondition($status);
        }

        $now = new DateTime();
        $now->setTime((int)$now->format('H'), (int)$now->format('i'), 59); // like EntryQuery: cacheable per minute
        $nowDb = Db::prepareDateForDb($now);
        $enabled = ['elements.enabled' => true, 'elements_sites.enabled' => true];
        $dated = self::hasProducts() ? ['entries', 'commerce_products'] : ['entries'];

        $perTable = fn(callable $condition): array => array_map($condition, $dated);
        return match ($status) {
            Entry::STATUS_LIVE => [
                'and',
                $enabled,
                ...$perTable(fn(string $t): array => [
                    'or',
                    ["$t.id" => null], // not an element of this dated type
                    ['and', ['<=', "$t.postDate", $nowDb], ['or', ["$t.expiryDate" => null], ['>', "$t.expiryDate", $nowDb]]],
                ]),
            ],
            Entry::STATUS_PENDING => ['and', $enabled, ['or', ...$perTable(fn(string $t): array => ['>', "$t.postDate", $nowDb])]],
            Entry::STATUS_EXPIRED => ['and', $enabled, ['or', ...$perTable(fn(string $t): array => ['<=', "$t.expiryDate", $nowDb])]],
        };
    }

    /** @return list<int> the `usesElement` targets (ElementQueryBehavior) */
    private function targetIds(): array
    {
        $behavior = $this->getBehavior('elementRelations');
        $targets = $behavior instanceof ElementQueryBehavior ? $behavior->usesElement : null;
        if ($targets === null) {
            return [];
        }
        return array_values(array_filter(array_map(
            fn($target): int => $target instanceof ElementInterface ? (int)$target->id : (int)$target,
            is_array($targets) ? $targets : [$targets],
        )));
    }

    /**
     * The usages (relating element id per site) of the targets that the user may view: candidates from the cache,
     * narrowed by the coarse SQL permissions, then each checked with Elements::canView(). Memoized per request.
     * @param int[] $targetIds
     * @return array{pairs: array<int, int[]>} siteId => viewable element ids
     */
    private static function viewablePairs(array $targetIds, User $viewer): array
    {
        sort($targetIds);
        $key = $viewer->id . '|' . implode(',', $targetIds);
        if (isset(self::$_pairs[$key])) {
            return self::$_pairs[$key];
        }
        // an element with thousands of usages means thousands of permission checks: kept in Craft's data cache until
        // anything that could change the answer happens (see resetPermissionCache())
        $cache = Craft::$app->getCache();
        if (!$cache) {
            return self::$_pairs[$key] = self::computeViewablePairs($targetIds, $viewer);
        }
        /** @var array{pairs: array<int, int[]>} $cached */
        $cached = $cache->getOrSet(
            'element-relations:viewable:' . md5($key),
            fn(): array => self::computeViewablePairs($targetIds, $viewer),
            600, // a safety net: permissions saved in code without saving the user fire no event
            new \yii\caching\TagDependency(['tags' => [self::PERMISSION_CACHE_TAG]]),
        );
        return self::$_pairs[$key] = $cached;
    }

    /**
     * @param int[] $targetIds
     * @return array{pairs: array<int, int[]>}
     */
    private static function computeViewablePairs(array $targetIds, User $viewer): array
    {
        $base = (new Query())
            ->select(['id' => 'elements.id', 'siteId' => 'elements_sites.siteId', 'type' => 'elements.type'])
            ->distinct()
            ->from(['cache' => '{{%elementrelations_cache}}'])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[cache.sourcePrimaryOwnerId]]')
            ->innerJoin(['elements_sites' => Table::ELEMENTS_SITES], '[[elements_sites.elementId]] = [[elements.id]] AND [[elements_sites.siteId]] = [[cache.sourceSiteId]]')
            ->leftJoin(['entries' => Table::ENTRIES], '[[entries.id]] = [[elements.id]]')
            ->leftJoin(['assets' => Table::ASSETS], '[[assets.id]] = [[elements.id]]')
            ->leftJoin(['categories' => Table::CATEGORIES], '[[categories.id]] = [[elements.id]]')
            ->where(['cache.targetElementId' => $targetIds, 'elements.dateDeleted' => null, 'elements.revisionId' => null])
            ->andWhere(['not', ['cache.sourceElementId' => null]]);
        /** @var list<array{id: int|string, siteId: int|string, type: string}> $candidates */
        $candidates = $base->andWhere(self::viewCondition($viewer))->all();

        $byTypeAndSite = [];
        foreach ($candidates as $row) {
            $byTypeAndSite[$row['type']][(int)$row['siteId']][] = (int)$row['id'];
        }
        $elementsService = Craft::$app->getElements();
        $pairs = [];
        foreach ($byTypeAndSite as $type => $sites) {
            if (!is_subclass_of($type, ElementInterface::class)) {
                continue;
            }
            foreach ($sites as $siteId => $ids) {
                foreach (array_chunk($ids, 500) as $chunk) { // bounded memory for thousands of usages
                    /** @var ElementInterface[] $loaded */
                    $loaded = $type::find()->id($chunk)->siteId($siteId)->status(null)->drafts(null)->provisionalDrafts(null)->all();
                    foreach ($loaded as $element) {
                        if ($elementsService->canView($element, $viewer)) {
                            $pairs[$siteId][] = (int)$element->id;
                        }
                    }
                }
            }
        }
        return ['pairs' => $pairs];
    }

    /**
     * Forgets the permission results, in this request and in Craft's data cache. Called whenever the answer could change:
     * relations written, any element saved, deleted or restored, users assigned to groups, project config changed
     * (sections, volumes, group permissions); see ElementRelations::init().
     */
    public static function resetPermissionCache(): void
    {
        self::$_pairs = [];
        $cache = Craft::$app->getCache();
        if ($cache) {
            \yii\caching\TagDependency::invalidate($cache, self::PERMISSION_CACHE_TAG);
        }
    }

    /** The user to restrict to: explicit, or the logged-in non-admin user of a control panel request. */
    private function viewer(): ?User
    {
        if ($this->_viewableBy instanceof User) {
            return $this->_viewableBy->admin ? null : $this->_viewableBy;
        }
        if ($this->_viewableBy === false) {
            return null;
        }
        $request = Craft::$app->getRequest();
        if (!$request instanceof \craft\web\Request || !$request->getIsCpRequest()) {
            return null; // console, queue and site requests are not restricted
        }
        $user = Craft::$app->getUser()->getIdentity();
        return $user && !$user->admin ? $user : null;
    }

    /**
     * The coarse part of Craft's view permissions as SQL, for the core element types: only what the user could see
     * at all (section, volume, category group, global set, other users, sites). Finer rules (other authors' entries,
     * drafts, temporary uploads, plugin authorization events) are checked per element in viewablePairs().
     * @return array<int|string, mixed>
     */
    private static function viewCondition(User $user): array
    {
        // ids of the sections/volumes/groups/sets whose permission (`viewEntries:<uid>`, ...) the user has
        $uidsAllowed = fn(array $items, string $permission): array => array_values(array_filter(array_map(
            fn(Section|Volume|CategoryGroup|GlobalSet $item): ?int => $user->can(sprintf($permission, $item->uid)) ? $item->id : null,
            $items,
        )));
        $sectionIds = $uidsAllowed(Craft::$app->getEntries()->getAllSections(), 'viewEntries:%s');
        $volumeIds = array_values(array_unique([
            ...$uidsAllowed(Craft::$app->getVolumes()->getAllVolumes(), 'viewAssets:%s'),
            ...$uidsAllowed(Craft::$app->getVolumes()->getAllVolumes(), 'viewPeerAssets:%s'),
        ]));
        $groupIds = $uidsAllowed(Craft::$app->getCategories()->getAllGroups(), 'viewCategories:%s');
        $globalSetIds = $uidsAllowed(Craft::$app->getGlobals()->getAllSets(), 'editGlobalSet:%s');
        $core = [Entry::class, Asset::class, Category::class, User::class, GlobalSet::class];

        $condition = [
            'or',
            ['not in', 'elements.type', $core], // plugin types: checked per element in populate()
            ['and', ['elements.type' => Entry::class], ['entries.sectionId' => $sectionIds ?: [0]]],
            ['and', ['elements.type' => Asset::class], ['assets.volumeId' => $volumeIds ?: [0]]],
            ['and', ['elements.type' => Category::class], ['categories.groupId' => $groupIds ?: [0]]],
            ['and', ['elements.type' => GlobalSet::class], ['elements.id' => $globalSetIds ?: [0]]],
            $user->can('viewUsers')
                ? ['elements.type' => User::class]
                : ['and', ['elements.type' => User::class], ['elements.id' => $user->id]],
        ];

        if (Craft::$app->getIsMultiSite()) {
            $siteIds = array_values(array_filter(array_map(
                fn(Site $site): ?int => $user->can("editSite:$site->uid") ? $site->id : null,
                Craft::$app->getSites()->getAllSites(),
            )));
            $condition = ['and', $condition, ['elements_sites.siteId' => $siteIds ?: [0]]];
        }
        return $condition;
    }

    private static function hasProducts(): bool
    {
        return Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    /**
     * @param list<array{id: int|string, siteId: int|string}> $rows
     * @return list<ElementInterface>|array<array<string, mixed>>
     */
    public function populate($rows): array
    {
        if ($this->asArray) {
            return parent::populate($rows);
        }

        // the element type of each row (not selected by the base query, and adding it to its select would break
        // Craft's search param, which reuses the query as an `id IN (...)` subquery)
        /** @var array<int, string> $types */
        $types = (new Query())
            ->select(['id', 'type'])
            ->from(Table::ELEMENTS)
            ->where(['id' => array_column($rows, 'id')])
            ->pairs();

        $idsByTypeAndSite = [];
        foreach ($rows as $row) {
            $idsByTypeAndSite[$types[$row['id']] ?? ''][$row['siteId']][] = (int)$row['id'];
        }

        $elements = [];
        foreach ($idsByTypeAndSite as $type => $sites) {
            if (!is_subclass_of($type, ElementInterface::class)) {
                continue;
            }
            foreach ($sites as $siteId => $ids) {
                /** @var ElementInterface[] $loaded */
                $loaded = $type::find()
                    ->id($ids)
                    ->siteId($siteId)
                    ->status(null)
                    ->drafts(null)
                    ->provisionalDrafts(null)
                    ->revisions(null)
                    ->trashed(null)
                    ->all();
                foreach ($loaded as $element) {
                    $elements["$type:$siteId:$element->id"] = $element;
                    Usage::$via[(int)$element->id] = []; // reset: an earlier list in this request may have used another target
                }
            }
        }

        // through which field(s) each loaded element uses the target (for the index's "Field" column)
        $targetIds = $this->targetIds();
        if ($targetIds && $elements) {
            // with the state of the element holding the relation: a disabled Matrix block (nested entry) of a live owner
            // is no live usage, which the column says
            /** @var list<array{sourcePrimaryOwnerId: int|string, sourceSiteId: int|string, sourceElementId: int|string|null, fieldId: int|string|null, type: string, enabled: bool|int|string|null, enabledForSite: bool|int|string|null}> $rowsVia */
            $rowsVia = (new Query())
                ->select([
                    'sourcePrimaryOwnerId' => 'cache.sourcePrimaryOwnerId', 'sourceSiteId' => 'cache.sourceSiteId',
                    'sourceElementId' => 'cache.sourceElementId', 'fieldId' => 'cache.fieldId', 'type' => 'cache.type',
                    'enabled' => 'sources.enabled', 'enabledForSite' => 'sources_sites.enabled',
                ])
                ->distinct()
                ->from(['cache' => '{{%elementrelations_cache}}'])
                ->leftJoin(['sources' => Table::ELEMENTS], '[[sources.id]] = [[cache.sourceElementId]]')
                ->leftJoin(['sources_sites' => Table::ELEMENTS_SITES], '[[sources_sites.elementId]] = [[cache.sourceElementId]] AND [[sources_sites.siteId]] = [[cache.sourceSiteId]]')
                ->where(['cache.targetElementId' => $targetIds, 'cache.sourcePrimaryOwnerId' => array_column($rows, 'id')])
                // a list spanning every site names the fields and sites of all of them; one site's list, that site's
                ->andFilterWhere(['cache.sourceSiteId' => $this->allSites ? null : array_values(array_unique(array_map(intval(...), array_column($rows, 'siteId'))))])
                ->all();
            Usage::$sites = [];
            foreach ($rowsVia as $via) {
                Usage::$sites[(int)$via['sourcePrimaryOwnerId']][(int)$via['sourceSiteId']] = true;
                $nested = $via['sourceElementId'] !== null && (int)$via['sourceElementId'] !== (int)$via['sourcePrimaryOwnerId'];
                $disabled = $nested && (($via['enabled'] !== null && !(bool)$via['enabled']) || ($via['enabledForSite'] !== null && !(bool)$via['enabledForSite']));
                Usage::$via[(int)$via['sourcePrimaryOwnerId']][] = [
                    'fieldId' => $via['fieldId'] ? (int)$via['fieldId'] : null,
                    'type' => $via['type'],
                    'disabledIn' => $disabled ? (int)$via['sourceElementId'] : null,
                ];
            }
        }

        // keep the order of this query (sorting, pagination); the stand-in is a last line of defence only, restricted
        // queries already contain nothing but viewable elements (see beforePrepare())
        $viewer = $this->viewer();
        $elementsService = Craft::$app->getElements();
        $ordered = [];
        foreach ($rows as $row) {
            $key = ($types[$row['id']] ?? '') . ":{$row['siteId']}:{$row['id']}";
            if (isset($elements[$key])) {
                $ordered[] = $viewer && !$elementsService->canView($elements[$key], $viewer)
                    ? RestrictedUsage::fromElement($elements[$key])
                    : $elements[$key];
            }
        }
        return $ordered;
    }
}
