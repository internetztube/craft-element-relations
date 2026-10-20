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

    public function __construct(int $elementId, int $siteId)
    {
        $this->elementId = $elementId;
        $this->siteId = $siteId;
    }

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
     * @param string|string[]|null $status
     * @param class-string<ElementInterface>[]|null $types
     */
    public function getCount(array|int|null $siteIds = null, array|string|null $sections = null, array|string|null $entryTypes = null, array|string|null $status = null, ?bool $drafts = null, ?array $types = null, ?string $search = null): int
    {
        $queries = $this->getElementQueries($siteIds, $sections, $entryTypes, $status, $drafts, $types, $search);
        return array_sum(array_map(fn(ElementQueryInterface $query): int => (int)$query->count(), $queries));
    }

    /** @param int|int[]|null $siteIds */
    public function getCountFast(array|int|null $siteIds = null): int
    {
        $siteIds = $this->normalizeSiteIds($siteIds);
        $rows = array_filter(
            $this->getFastCountQueryResult(),
            // loose: site ids may come from templates as strings
            fn(array $row): bool => empty($siteIds) || in_array($row['siteId'], $siteIds),
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
     * @param string|string[]|null $status
     */
    public function getIsInUse(array|int|null $siteIds = null, array|string|null $sections = null, array|string|null $entryTypes = null, array|string|null $status = null): bool
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
            $rows = (new Query())
                ->select(['targetElementId', 'targetSiteId', 'type', 'globalKey'])
                ->from('{{%elementrelations_cache}}')
                ->where(['targetElementId' => $this->elementId, 'sourceElementId' => null])
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
        $counts = [];
        foreach ($this->getFastCountQueryResult() as $row) {
            // element types of uninstalled plugins stay in the cache until their elements are deleted
            if (is_subclass_of($row['type'], ElementInterface::class)) {
                $counts[$row['type']] = ($counts[$row['type']] ?? 0) + $row['count'];
            }
        }
        arsort($counts);
        return $counts;
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

    /** How many (primary owner) elements relate to this element, in any site. One COUNT query. */
    public function getSourceOwnerCount(): int
    {
        return (int)$this->fastSubQuery()->count('DISTINCT [[elementrelations_cache.sourcePrimaryOwnerId]]');
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
     * @param string|string[]|null $status
     * @param class-string<ElementInterface>[]|null $types restrict to these element types
     * @return list<ElementQuery<array-key, ElementInterface>>
     */
    public function getElementQueries(array|int|null $siteIds = null, array|string|null $sections = null, array|string|null $entryTypes = null, array|string|null $status = null, ?bool $drafts = null, ?array $types = null, ?string $search = null): array
    {
        $siteIds = $this->normalizeSiteIds($siteIds);
        if (is_string($sections)) $sections = [$sections];
        if (is_string($entryTypes)) $entryTypes = [$entryTypes];
        $factory = $this->queryFactory;
        if ($factory) $drafts = false;
        // front-end templates: unless asked for, only what a visitor may see (live entries, enabled elements, no
        // drafts); the control panel and console keep "everything" as the default
        $siteDefaults = !$factory && self::isSiteRequest();
        if ($siteDefaults && $drafts === null) $drafts = false;

        $queries = [];
        foreach ($this->getElementTypes() as $elementType) {
            if ($types !== null && !in_array($elementType, $types, true)) {
                continue;
            }
            $query = $factory ? $factory($elementType) : $elementType::find();
            if (!$query instanceof ElementQuery) {
                continue;
            }
            Craft::configure($query, ['usesElement' => $this->elementId]); // behavior param, see ElementQueryBehavior
            $query
                ->revisions(false)
                ->provisionalDrafts(false);
            if ($status !== null || (!$factory && !$siteDefaults)) {
                $query->status($status);
            }
            // the site of the relating element; `usesElement` matches the cache rows of that site
            empty($siteIds) ? $query->site('*') : $query->siteId($siteIds);

            if ($search) {
                $query->search($search);
            }
            if ($query instanceof EntryQuery) {
                if (!empty($sections)) $query->section($sections);
                if (!empty($entryTypes)) $query->type($entryTypes);
            }

            if ($drafts === null) {
                $queries[] = (clone $query)->drafts(true);
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
     * @param string|string[]|null $status
     * @param class-string<ElementInterface>[]|null $types
     * @return list<ElementInterface>
     */
    public function getElements(array|int|null $siteIds = null, ?int $limit = null, int $offset = 0, array|string|null $sections = null, array|string|null $entryTypes = null, array|string|null $status = null, ?bool $drafts = null, ?array $types = null, ?string $search = null): array
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
     * @param string|string[]|null $status
     * @param class-string<ElementInterface>[]|null $types
     * @return \Generator<int, ElementInterface>
     */
    public function getElementsIterator(array|int|null $siteIds = null, ?int $limit = null, int $offset = 0, int $batchSize = 100, array|string|null $sections = null, array|string|null $entryTypes = null, array|string|null $status = null, ?bool $drafts = null, ?array $types = null, ?string $search = null): \Generator
    {
        $remaining = $limit;

        foreach ($this->getElementQueries($siteIds, $sections, $entryTypes, $status, $drafts, $types, $search) as $query) {
            $total = (int)$query->count();
            if ($offset >= $total) {
                $offset -= $total;
                continue;
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
        return (new Query())
            ->from(['elementrelations_cache' => '{{%elementrelations_cache}}'])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[elementrelations_cache.sourcePrimaryOwnerId]]')
            ->leftJoin(['drafts' => Table::DRAFTS], '[[drafts.id]] = [[elements.draftId]]')
            ->where(['[[elementrelations_cache.targetElementId]]' => $this->elementId])
            ->andWhere(['[[elements.dateDeleted]]' => null])
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
