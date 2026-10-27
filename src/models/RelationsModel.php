<?php

declare(strict_types=1);

namespace internetztube\elementRelations\models;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use craft\elements\db\ElementQueryInterface;
use craft\elements\db\EntryQuery;
use craft\models\Site;
use internetztube\elementRelations\services\extractors\GlobalUsageSeomaticService;
use internetztube\elementRelations\services\extractors\InterfaceGlobalUsage;
use internetztube\elementRelations\ElementRelations;

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
    public const GLOBAL_USAGE_PROVIDERS = [
        GlobalUsageSeomaticService::class,
    ];

    /** Lets plugins add providers of non-element usages (InterfaceGlobalUsage) via `$event->types`. */
    public const EVENT_REGISTER_GLOBAL_USAGE_PROVIDERS = 'registerGlobalUsageProviders';

    /** @var list<class-string<InterfaceGlobalUsage>>|null */
    private static ?array $providers = null;

    /** @return list<class-string<InterfaceGlobalUsage>> */
    public static function globalUsageProviders(): array
    {
        if (self::$providers === null) {
            $types = self::GLOBAL_USAGE_PROVIDERS;
            if (\yii\base\Event::hasHandlers(self::class, self::EVENT_REGISTER_GLOBAL_USAGE_PROVIDERS)) {
                $event = new \craft\events\RegisterComponentTypesEvent(['types' => $types]);
                \yii\base\Event::trigger(self::class, self::EVENT_REGISTER_GLOBAL_USAGE_PROVIDERS, $event);
                $types = $event->types;
            }
            /** @var list<class-string<InterfaceGlobalUsage>> $types handlers are asked to add InterfaceGlobalUsage classes */
            self::$providers = array_values(array_filter($types, 'class_exists'));
        }
        return self::$providers;
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
        /** @var list<array{target: int|string, siteId: int|string, count: int|string}> $rows */
        $rows = self::fastSubQueryFor($elementIds)
            ->select(['target' => '[[elementrelations_cache.targetElementId]]', 'siteId' => '[[elementrelations_cache.targetSiteId]]', 'count' => 'COUNT(DISTINCT [[elementrelations_cache.sourcePrimaryOwnerId]])'])
            ->andWhere(['not', ['[[elementrelations_cache.targetSiteId]]' => $siteId]])
            ->groupBy(['[[elementrelations_cache.targetElementId]]', '[[elementrelations_cache.targetSiteId]]'])
            ->all();
        // plugin settings that use another site's version (SEOmatic's German settings) count too, one per setting
        /** @var list<array{target: int|string, siteId: int|string, count: int|string}> $globalRows */
        $globalRows = (new Query())
            ->select(['target' => 'targetElementId', 'siteId' => 'targetSiteId', 'count' => 'COUNT(DISTINCT [[globalKey]])'])
            ->from('{{%elementrelations_cache}}')
            ->where(ElementRelations::idIn('targetElementId', $elementIds))
            ->andWhere(['sourceElementId' => null])
            ->andWhere(['not', ['targetSiteId' => $siteId]])
            ->groupBy(['targetElementId', 'targetSiteId'])
            ->all();
        $order = array_flip(Craft::$app->getSites()->getAllSiteIds());
        $counts = [];
        foreach ([...$rows, ...$globalRows] as $row) {
            $counts[(int)$row['target']][(int)$row['siteId']] = ($counts[(int)$row['target']][(int)$row['siteId']] ?? 0) + (int)$row['count'];
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
        return array_sum(array_map(fn(ElementQueryInterface $query): int => (int)$query->count(), $queries));
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
        return array_filter(
            Craft::$app->getSites()->getAllSites(),
            fn(Site $site): bool => $this->getCount($site->id) > 0,
        );
    }

    /** @return array<int, Site> */
    public function getSitesFast(): array
    {
        $siteIds = array_column($this->getFastCountQueryResult(), 'siteId');
        return array_filter(
            Craft::$app->getSites()->getAllSites(),
            fn(Site $site): bool => in_array($site->id, $siteIds, true),
        );
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
            /** @var list<array{targetElementId: int|string, targetSiteId: int|string, type: string, globalKey: string|null}> $rows */
            $rows = $this->prefetched()['globalRows'] ?? (new Query())
                ->select(['targetElementId', 'targetSiteId', 'type', 'globalKey'])
                ->from('{{%elementrelations_cache}}')
                ->where(['targetElementId' => $this->elementId, 'sourceElementId' => null])
                ->andFilterWhere(['targetSiteId' => $this->targetSiteId])
                ->all();
            $rowsByType = [];
            foreach ($rows as $row) {
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
            /** @var list<array{type: string, count: int|string, drafts: int|string}> $rows */
            $rows = $this->fastSubQuery()
                ->select([
                    'type' => '[[elements.type]]',
                    'count' => 'COUNT(DISTINCT [[elementrelations_cache.sourcePrimaryOwnerId]])',
                    'drafts' => 'COUNT(DISTINCT CASE WHEN [[elements.draftId]] IS NOT NULL THEN [[elementrelations_cache.sourcePrimaryOwnerId]] END)',
                ])
                ->groupBy(['[[elements.type]]'])
                ->all();
            $counts = [];
            foreach ($rows as $row) {
                // element types of uninstalled plugins stay in the cache until their elements are deleted
                if (is_subclass_of($row['type'], ElementInterface::class)) {
                    $counts[$row['type']] = ['count' => (int)$row['count'], 'drafts' => (int)$row['drafts']];
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
        $counts = array_fill_keys(Craft::$app->getSites()->getAllSiteIds(), 0);
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
        $query = $this->fastSubQuery()->select(['[[elementrelations_cache.sourcePrimaryOwnerId]]'])->distinct()->groupBy(null);
        if (!empty($siteIds)) {
            $query->andWhere(['[[elementrelations_cache.sourceSiteId]]' => $siteIds]);
        }
        return array_map(intval(...), $query->column());
    }

    /**
     * getSourceElementIds() for every site at once: the (primary owner) elements that relate to this element, per site
     * of theirs. One query.
     * @return array<int, list<int>> owner ids by site id
     */
    public function getSourceElementIdsBySite(): array
    {
        $bySite = [];
        foreach ($this->fastSubQuery()->select(['owner' => '[[elementrelations_cache.sourcePrimaryOwnerId]]', 'site' => '[[elementrelations_cache.sourceSiteId]]'])->distinct()->all() as $row) {
            /** @var array{owner: int|string, site: int|string} $row */
            $bySite[(int)$row['site']][] = (int)$row['owner'];
        }
        return $bySite;
    }

    /** How many (primary owner) elements relate to this element, in any site. One COUNT query. */
    public function getSourceOwnerCount(): int
    {
        return $this->_sourceOwnerCount ??= $this->prefetched()['owners']
            ?? (int)$this->fastSubQuery()->count('DISTINCT [[elementrelations_cache.sourcePrimaryOwnerId]]');
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
        $target = '[[elementrelations_cache.targetElementId]]';
        $owner = '[[elementrelations_cache.sourcePrimaryOwnerId]]';

        /** @var list<array{target: int|string, type: string, count: int|string, drafts: int|string}> $typeRows */
        $typeRows = self::fastSubQueryFor($elementIds, $siteId)
            ->select(['target' => $target, 'type' => '[[elements.type]]', 'count' => "COUNT(DISTINCT $owner)", 'drafts' => "COUNT(DISTINCT CASE WHEN [[elements.draftId]] IS NOT NULL THEN $owner END)"])
            ->groupBy([$target, '[[elements.type]]'])
            ->all();
        foreach ($typeRows as $row) {
            // an element has one type, so the counts per type add up to the relating elements
            $data[(int)$row['target']]['owners'] += (int)$row['count'];
            if (is_subclass_of($row['type'], ElementInterface::class)) {
                $data[(int)$row['target']]['types'][$row['type']] = ['count' => (int)$row['count'], 'drafts' => (int)$row['drafts']];
            }
        }
        foreach ($data as &$element) {
            uasort($element['types'], fn(array $a, array $b): int => $b['count'] <=> $a['count']);
        }
        unset($element);

        /** @var list<array{targetElementId: int|string, targetSiteId: int|string, type: string, globalKey: string|null}> $globalRows */
        $globalRows = (new Query())
            ->select(['targetElementId', 'targetSiteId', 'type', 'globalKey'])
            ->from('{{%elementrelations_cache}}')
            ->where(ElementRelations::idIn('targetElementId', $elementIds))
            ->andWhere(['sourceElementId' => null, 'targetSiteId' => $siteId])
            ->all();
        foreach ($globalRows as $row) {
            $data[(int)$row['targetElementId']]['globalRows'][] = $row;
        }

        // the first relating elements of each listed element (window function: PostgreSQL, MySQL 8)
        $ranked = self::fastSubQueryFor($elementIds, $siteId)
            ->select([
                'target' => $target,
                'owner' => $owner,
                'type' => '[[elements.type]]',
                'siteId' => "MIN(CASE WHEN [[elementrelations_cache.sourceSiteId]] = $preferredSiteId THEN 0 ELSE [[elementrelations_cache.sourceSiteId]] END)",
                'pos' => "ROW_NUMBER() OVER (PARTITION BY $target ORDER BY MAX([[elements.dateUpdated]]) DESC, $owner DESC)",
            ])
            ->groupBy([$target, $owner, '[[elements.type]]']);
        /** @var list<array{target: int|string, owner: int|string, type: string, siteId: int|string, pos: int|string}> $first */
        $first = (new Query())->from(['ranked' => $ranked])->where(['<=', 'pos', $limit])->orderBy(['target' => SORT_ASC, 'pos' => SORT_ASC])->all();
        $idsByTypeAndSite = [];
        foreach ($first as $row) {
            if (is_subclass_of($row['type'], ElementInterface::class)) {
                $idsByTypeAndSite[$row['type']][(int)$row['siteId'] ?: $preferredSiteId][] = (int)$row['owner'];
            }
        }
        $loaded = [];
        foreach ($idsByTypeAndSite as $type => $idsBySite) {
            foreach ($idsBySite as $siteId => $ids) {
                $query = ElementRelations::find($type)->id(array_values(array_unique($ids)))->siteId($siteId)->status(null);
                if ($query instanceof ElementQuery) {
                    $query->drafts(null)->provisionalDrafts(false)->revisions(false);
                }
                foreach ($query->all() as $element) {
                    if ($element instanceof ElementInterface) {
                        $loaded[(int)$element->id] = $element;
                    }
                }
            }
        }
        foreach ($first as $row) {
            if (isset($loaded[(int)$row['owner']])) {
                $data[(int)$row['target']]['preview'][] = $loaded[(int)$row['owner']];
            }
        }
        foreach (self::otherSiteCountsFor($elementIds, $siteId) as $id => $counts) {
            $data[$id]['otherSites'] = $counts;
        }
        foreach ($data as $id => $element) {
            self::$prefetched["$id:$siteId"] = $element;
        }
    }

    /** A site (front-end) web request, where templates render for visitors. */
    private static function isSiteRequest(): bool
    {
        $request = Craft::$app->getRequest();
        return $request instanceof \craft\web\Request && !$request->getIsCpRequest();
    }

    /**
     * One query per element type (and per drafts/no-drafts unless `$drafts` is given), all using the `usesElement` param.
     * With a query factory (withQueryFactory()) only the types it returns a query for, without drafts.
     * @param int|int[]|null $siteIds
     * @param string|string[]|null $sections
     * @param string|string[]|null $entryTypes
     * @param string|string[]|null $status null: every status; omitted: see DEFAULT
     * @param class-string<ElementInterface>[]|null $types restrict to these element types
     * @return list<ElementQuery<array-key, ElementInterface>>
     */
    public function getElementQueries(array|int|null $siteIds = null, array|string|null $sections = null, array|string|null $entryTypes = null, array|string|null $status = self::DEFAULT, bool|string|null $drafts = self::DEFAULT, ?array $types = null, ?string $search = null): array
    {
        $siteIds = $this->normalizeSiteIds($siteIds);
        if (is_string($sections)) $sections = [$sections];
        if (is_string($entryTypes)) $entryTypes = [$entryTypes];
        $factory = $this->queryFactory;
        // front-end templates: unless asked for, only what a visitor may see (live entries, enabled elements, no
        // drafts); the control panel and console keep "everything" as the default (see DEFAULT)
        $siteDefaults = !$factory && self::isSiteRequest();
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
            if ((!empty($sections) || !empty($entryTypes)) && !is_a($elementType, \craft\elements\Entry::class, true)) {
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
            Craft::configure($query, ['usesElement' => $this->elementId, 'usesElementSite' => $this->targetSiteId]); // behavior params, see ElementQueryBehavior
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
                $total = (int)$query->count();
                if ($offset >= $total) {
                    $offset -= $total;
                    continue;
                }
            }

            $localOffset = $offset;
            $offset = 0;

            while (true) {
                $take = $remaining === null ? $batchSize : min($batchSize, $remaining);
                /** @var ElementInterface[] $batch */
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

    /**
     * Cache rows pointing at this element, joined to their (live, non-revision, non-provisional) source elements.
     * @return Query<array-key, mixed>
     */
    private function fastSubQuery(): Query
    {
        return self::fastSubQueryFor($this->elementId, $this->targetSiteId);
    }

    /**
     * fastSubQuery() for one or several elements (and only their versions in one site).
     * @param int|list<int> $elementIds
     * @return Query<array-key, mixed>
     */
    private static function fastSubQueryFor(int|array $elementIds, ?int $targetSiteId = null): Query
    {
        return (new Query())
            ->from(['elementrelations_cache' => '{{%elementrelations_cache}}'])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[elementrelations_cache.sourcePrimaryOwnerId]]')
            ->leftJoin(['drafts' => Table::DRAFTS], '[[drafts.id]] = [[elements.draftId]]')
            ->where(is_int($elementIds) ? ['[[elementrelations_cache.targetElementId]]' => $elementIds] : ElementRelations::idIn('elementrelations_cache.targetElementId', $elementIds))
            ->andFilterWhere(['[[elementrelations_cache.targetSiteId]]' => $targetSiteId])
            ->andWhere(['[[elements.dateDeleted]]' => null])
            // elements of a type Craft no longer knows (a plugin removed or disabled) are listed nowhere, so not counted
            ->andWhere(['[[elements.type]]' => Craft::$app->getElements()->getAllElementTypes()])
            ->andWhere(['[[elements.archived]]' => 0])
            ->andWhere(['[[elements.revisionId]]' => null])
            ->andWhere(['or', ['[[drafts.id]]' => null], ['<>', '[[drafts.provisional]]', 1]]);
    }

    /** @return list<array{siteId: int, type: string, count: int}> */
    private function getFastCountQueryResult(): array
    {
        if ($this->_fastCountQueryResult === null) {
            $subQuery = $this->fastSubQuery()
                ->select([
                    'siteId' => '[[elementrelations_cache.sourceSiteId]]',
                    'type' => '[[elements.type]]',
                ])
                ->groupBy([
                    '[[elementrelations_cache.sourcePrimaryOwnerId]]',
                    '[[elementrelations_cache.sourceSiteId]]',
                    '[[elements.type]]',
                ]);

            /** @var list<array{siteId: int|string, type: string, count: int|string}> $rows */
            $rows = (new Query())
                ->select(['siteId' => '[[siteId]]', 'type' => '[[type]]', 'count' => 'COUNT(*)'])
                ->from(['sub' => $subQuery])
                ->groupBy(['[[siteId]]', '[[type]]'])
                ->all();
            // MySQL returns numbers as strings, PostgreSQL as ints
            $this->_fastCountQueryResult = array_map(fn(array $row): array => [
                'siteId' => (int)$row['siteId'],
                'type' => $row['type'],
                'count' => (int)$row['count'],
            ], $rows);
        }
        return $this->_fastCountQueryResult;
    }
}
