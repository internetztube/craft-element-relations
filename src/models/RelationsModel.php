<?php

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
 * `status` is passed through to the element queries; `null` returns elements of every status.
 * `drafts`: `null` = canonical elements and drafts, `false` = canonical only, `true` = drafts only.
 */
class RelationsModel
{
    /** @var class-string<InterfaceGlobalUsage>[] usages that are not elements (plugin settings) */
    private const GLOBAL_USAGE_PROVIDERS = [
        GlobalUsageSeomaticService::class,
    ];

    private int $elementId;
    private int $siteId;

    private ?array $_globalUsages = null;
    private ?array $_fastCountQueryResult = null;

    public function __construct(int $elementId, int $siteId)
    {
        $this->elementId = $elementId;
        $this->siteId = $siteId;
    }

    /** The element whose usages this model describes (always the canonical element). */
    public function getElementId(): int
    {
        return $this->elementId;
    }

    public function getCount(array|int|null $siteIds = null, array|string|null $sections = null, array|string|null $entryTypes = null, array|string|null $status = null, ?bool $drafts = null, ?array $types = null, ?string $search = null): int
    {
        return (int)collect($this->getElementQueries($siteIds, $sections, $entryTypes, $status, $drafts, $types, $search))
            ->map(fn(ElementQueryInterface $query) => $query->count())
            ->sum();
    }

    public function getCountFast(array|int|null $siteIds = null): int
    {
        $siteIds = $this->normalizeSiteIds($siteIds);
        return (int)collect($this->getFastCountQueryResult())
            ->filter(fn($row) => empty($siteIds) || in_array($row['siteId'], $siteIds))
            ->sum('count');
    }

    /** @return Site[] */
    public function getSites(): array
    {
        return collect(Craft::$app->getSites()->getAllSites())
            ->filter(fn(Site $site) => $this->getCount($site->id) > 0)
            ->all();
    }

    /** @return Site[] */
    public function getSitesFast(): array
    {
        $siteIds = collect($this->getFastCountQueryResult())->pluck('siteId')->unique()->all();
        return collect(Craft::$app->getSites()->getAllSites())
            ->filter(fn(Site $site) => in_array($site->id, $siteIds))
            ->all();
    }

    public function getIsInUse(array|int|null $siteIds = null, array|string|null $sections = null, array|string|null $entryTypes = null, array|string|null $status = null): bool
    {
        return $this->getCount($siteIds, $sections, $entryTypes, $status) > 0 || $this->getGlobalUsages();
    }

    public function getIsInUseFast(array|int|null $siteIds = null): bool
    {
        return $this->getCountFast($siteIds) > 0 || $this->getGlobalUsages();
    }

    /**
     * Usages that are not elements: plugin settings such as SEOmatic's global SEO image.
     * @return GlobalUsage[]
     */
    public function getGlobalUsages(): array
    {
        if ($this->_globalUsages === null) {
            $this->_globalUsages = [];
            foreach (self::GLOBAL_USAGE_PROVIDERS as $provider) {
                $this->_globalUsages = [...$this->_globalUsages, ...$provider::usages($this->elementId)];
            }
        }
        return $this->_globalUsages;
    }

    public function getIsUsedInSeomaticGlobalSettings(): bool
    {
        return collect($this->getGlobalUsages())->contains(fn(GlobalUsage $usage) => $usage->provider === 'seomatic');
    }

    /**
     * Element types of the elements that relate to this element (in any site), most used first.
     * @return class-string<ElementInterface>[]
     */
    public function getElementTypes(): array
    {
        return collect($this->getFastCountQueryResult())
            ->groupBy('type')
            ->map(fn($rows) => $rows->sum('count'))
            ->sortDesc()
            ->keys()
            ->filter(fn(string $type) => class_exists($type))
            ->values()
            ->all();
    }

    /**
     * Number of relating elements per element type (all sites), most used first.
     * @return array<class-string<ElementInterface>, int>
     */
    public function getCountsByType(): array
    {
        return collect($this->getFastCountQueryResult())
            ->filter(fn($row) => class_exists($row['type']))
            ->groupBy('type')
            ->map(fn($rows) => (int)$rows->sum('count'))
            ->sortDesc()
            ->all();
    }

    /**
     * IDs of the (primary owner) elements that relate to this element. Cheap: one query, no element hydration.
     * @return int[]
     */
    public function getSourceElementIds(array|int|null $siteIds = null): array
    {
        $siteIds = $this->normalizeSiteIds($siteIds);
        $query = $this->fastSubQuery()->select(['[[elementrelations_cache.sourcePrimaryOwnerId]]'])->distinct()->groupBy(null);
        if (!empty($siteIds)) {
            $query->andWhere(['[[elementrelations_cache.sourceSiteId]]' => $siteIds]);
        }
        return array_map('intval', $query->column());
    }

    /**
     * One query per element type (and per drafts/no-drafts unless `$drafts` is given), all using the `usesElement` param.
     * @param class-string<ElementInterface>[]|null $types restrict to these element types
     * @return ElementQueryInterface[]
     */
    public function getElementQueries(array|int|null $siteIds = null, array|string|null $sections = null, array|string|null $entryTypes = null, array|string|null $status = null, ?bool $drafts = null, ?array $types = null, ?string $search = null): array
    {
        $siteIds = $this->normalizeSiteIds($siteIds);
        if (is_string($sections)) $sections = [$sections];
        if (is_string($entryTypes)) $entryTypes = [$entryTypes];

        return collect($this->getElementTypes())
            ->filter(fn(string $type) => $types === null || in_array($type, $types, true))
            ->map(function (string $elementType) use ($siteIds, $sections, $entryTypes, $status, $drafts, $search) {
                /** @var ElementQuery $query */
                $query = $elementType::find();
                Craft::configure($query, ['usesElement' => $this->elementId]); // behavior param, see ElementQueryBehavior
                $query
                    ->revisions(false)
                    ->provisionalDrafts(false)
                    ->status($status);
                // the site of the relating element; `usesElement` matches the cache rows of that site
                empty($siteIds) ? $query->site('*') : $query->siteId($siteIds);

                if ($search) {
                    $query->search($search);
                }
                if ($query instanceof EntryQuery) {
                    if (!empty($sections)) $query->section($sections);
                    if (!empty($entryTypes)) $query->type($entryTypes);
                }

                return $drafts === null
                    ? [(clone $query)->drafts(true), (clone $query)->drafts(false)]
                    : [$query->drafts($drafts)];
            })
            ->flatten()
            ->all();
    }

    /**
     * @return ElementInterface[]
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
     * @return \Generator<int, ElementInterface>
     */
    public function getElementsIterator(array|int|null $siteIds = null, ?int $limit = null, int $offset = 0, int $batchSize = 100, array|string|null $sections = null, array|string|null $entryTypes = null, array|string|null $status = null, ?bool $drafts = null, ?array $types = null, ?string $search = null): \Generator
    {
        $remaining = $limit;

        foreach ($this->getElementQueries($siteIds, $sections, $entryTypes, $status, $drafts, $types, $search) as $query) {
            $total = $query->count();
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

    private function normalizeSiteIds(array|int|null $siteIds): array
    {
        if ($siteIds === null) return [$this->siteId];
        if (is_int($siteIds)) return [$siteIds];
        return $siteIds;
    }

    /**
     * Cache rows pointing at this element, joined to their (live, non-revision, non-provisional) source elements.
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

            $this->_fastCountQueryResult = (new Query())
                ->select(['siteId' => '[[siteId]]', 'type' => '[[type]]', 'count' => 'COUNT(*)'])
                ->from(['sub' => $subQuery])
                ->groupBy(['[[siteId]]', '[[type]]'])
                ->all();
        }
        return $this->_fastCountQueryResult;
    }
}
