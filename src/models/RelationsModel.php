<?php

namespace internetztube\elementRelations\models;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\db\ActiveQuery;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use craft\elements\db\ElementQueryInterface;
use craft\models\Site;
use internetztube\elementRelations\services\DatabaseService;
use yii\db\Expression;

class RelationsModel
{
    private int $elementId;

    private ?bool $_isUsedInSeomaticGlobalSettings = null;
    private ?array $_fastCountQueryResult = null;

    public function __construct(int $elementId)
    {
        $this->elementId = $elementId;
    }

    /**
     * @param array|int $siteIds
     * @return mixed
     */
    public function getCount(array|int $siteIds = [])
    {
        if (!is_array($siteIds)) {
            $siteIds = [$siteIds];
        }

        $fastCountQueryResult = $this->getFastCountQueryResult();
        $collection = collect($fastCountQueryResult);

        if (!empty($siteIds)) {
            $collection = $collection->filter(fn ($row) => in_array($row['siteId'], $siteIds));
        }
        return $collection->sum('count');
    }

    public function getSites()
    {
        $fastCountQueryResult = $this->getFastCountQueryResult();
        $siteIds = collect($fastCountQueryResult)
            ->pluck('siteId')
            ->unique()
            ->all();

        $sites = Craft::$app->getSites()->getAllSites();
        return collect($sites)
            ->filter(fn(Site $site) => in_array($site->id, $siteIds))
            ->all();
    }

    public function getIsInUse(array|int $siteIds = []): bool
    {
        return $this->getCount($siteIds) > 0 || $this->getIsUsedInSeomaticGlobalSettings();
    }

    public function getIsUsedInSeomaticGlobalSettings(): bool
    {
        if (!is_null($this->_isUsedInSeomaticGlobalSettings)) {
            return $this->_isUsedInSeomaticGlobalSettings;
        }

        if (Craft::$app->plugins->isPluginEnabled('seomatic')) {
            $columnSelector = DatabaseService::jsonExtract("metaBundleSettings", ["seoImageIds"]);
            $this->_isUsedInSeomaticGlobalSettings = (new Query())
                ->from([\nystudio107\seomatic\records\MetaBundle::tableName()])
                ->where(['=', $columnSelector, "[\"{$this->elementId}\"]"])
                ->collect()
                ->isNotEmpty();
        } else {
            $this->_isUsedInSeomaticGlobalSettings = false;
        }

        return $this->_isUsedInSeomaticGlobalSettings;
    }

    /**
     * @param array $siteIds
     * @return ElementQueryInterface[]
     */
    public function getElementQueries(array $siteIds = []): array
    {
        $fastCountQueryResult = $this->getFastCountQueryResult();

        return collect($fastCountQueryResult)
            ->pluck('type')
            ->unique()
            ->map(function (string $elementType) use ($siteIds) {

                $subQuery = (new Query())
                    ->select(new Expression('1'))
                    ->from(['elementrelations_cache' => '{{%elementrelations_cache}}'])
                    ->where('[[elementrelations_cache.sourcePrimaryOwnerId]] = [[elements.id]] AND [[elementrelations_cache.sourceSiteId]] = [[elements_sites.siteId]]')
                    ->andWhere(['elementrelations_cache.targetElementId' => $this->elementId]);

                if (!empty($siteIds)) {
                    $subQuery->andWhere(['in', 'elementrelations_cache.targetSiteId', $siteIds]);
                }

                /** @var ElementQuery $query */
                $query = $elementType::find();
                $query = $query
                    ->revisions(false)
                    ->provisionalDrafts(false)
                    ->site('*')
                    ->status(null)
                    ->andWhere(['exists', $subQuery])
                    ->drafts(false);

                // echo "<pre>{$query->getRawSql()}</pre>";
                // die();

                return [
                    (clone $query)->drafts(true),
                    (clone $query)->drafts(false),
                ];
            })
            ->flatten()
            ->all();
    }

    /**
     * @param array|int $siteIds
     * @param int|null $limit
     * @param int $offset
     * @return Element[]
     */
    public function getElements(array|int $siteIds = [], ?int $limit = null, int $offset = 0): array
    {
        return iterator_to_array(
            $this->getElementsIterator($siteIds, $limit, $offset),
            false
        );
    }

    public function getElementsIterator(array|int $siteIds = [], ?int $limit = null, int $offset = 0, int $batchSize = 100): \Generator
    {
        $siteIds = (array)$siteIds;
        $remaining = $limit;

        foreach ($this->getElementQueries($siteIds) as $query) {
            // How many total in this site
            $total = $query->count();

            // Skip entire query if offset still ahead
            if ($offset >= $total) {
                $offset -= $total;
                continue;
            }

            // We'll page through this query in batches
            $localOffset = $offset;
            $offset = 0;

            // Keep pulling batches until we exhaust this site or hit our global limit
            while (true) {
                // Figure out how many to ask for this round
                $take = $batchSize;
                if ($remaining !== null) {
                    $take = min($take, $remaining);
                }

                // Clone, apply the running offsets + batch limit
                $q = clone $query;
                if ($localOffset > 0) {
                    $q->offset($localOffset);
                }
                $q->limit($take);

                $batch = $q->all();
                if (empty($batch)) {
                    break; // no more here
                }

                foreach ($batch as $element) {
                    yield $element;
                    if ($remaining !== null && --$remaining <= 0) {
                        return; // global limit reached
                    }
                }

                // If fewer than we asked, we’re done with this site
                $count = count($batch);
                if ($count < $take) {
                    break;
                }

                // Advance local offset for next batch
                $localOffset += $count;
            }
        }
    }

    private function getFastCountQueryResult(): array
    {
        if (!is_null($this->_fastCountQueryResult)) {
            return $this->_fastCountQueryResult;
        }
        $subQuery = (new Query())
            ->select([
                'siteId' => '[[elementrelations_cache.sourceSiteId]]',
                'type'   => '[[elements.type]]',
            ])
            ->from(['elementrelations_cache' => '{{%elementrelations_cache}}'])

            // 1) join by primary‐owner only
            ->innerJoin(
                ['elements' => Table::ELEMENTS],
                '[[elements.id]] = [[elementrelations_cache.sourcePrimaryOwnerId]]'
            )

            // 3) (optional) drafts logic
            ->leftJoin(
                ['drafts' => Table::DRAFTS],
                '[[drafts.id]] = [[elements.draftId]]'
            )

            // add drafts.provisional if to make sure is not 1

            // 4) restrict to our target element
            ->where(['[[elementrelations_cache.targetElementId]]' => $this->elementId])

            // 5) element‐level filters
            ->andWhere([
                '[[elements.dateDeleted]]' => null,
            ])
            ->andWhere(['[[elements.enabled]]' => 1])
            ->andWhere(['[[elements.archived]]' => 0])
            ->andWhere(['[[elements.revisionId]]' => null])
            ->andWhere(['[[elements.revisionId]]' => null])

            ->andWhere([
                'or',
                ['[[drafts.id]]'          => null],               // no draft
                ['<>', '[[drafts.provisional]]', 1],              // or not provisional
            ])

            ->groupBy([
                '[[elementrelations_cache.sourcePrimaryOwnerId]]',
                '[[elementrelations_cache.sourceSiteId]]',
            ]);

        $query = (new Query())
            ->select([
                'siteId' => '[[siteId]]',
                'type'   => '[[type]]',
                'count'  => 'COUNT(*)',
            ])
            ->from(['sub' => $subQuery])
            ->groupBy(['[[siteId]]', '[[type]]']);

        // echo "<pre>{$query->getRawSql()}</pre>";
        // die();

        $this->_fastCountQueryResult = $query->all();

        return $this->_fastCountQueryResult;
    }
}
