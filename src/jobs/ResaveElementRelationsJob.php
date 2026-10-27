<?php

declare(strict_types=1);

namespace internetztube\elementRelations\jobs;

use Craft;
use craft\base\NestedElementInterface;
use craft\base\ElementInterface;
use craft\queue\BaseJob;
use internetztube\elementRelations\services\ExtractorService;

/** Refreshes the relations cache of a batch of elements (QueueElementsForRefreshService; full rebuilds: RebuildRelationsJob). */
class ResaveElementRelationsJob extends BaseJob
{
    /** @var list<array{elementId: int|string, siteId: int|string}> ids are strings when they come from a MySQL query */
    public array $items = [];

    /** Items loaded per element query: a job holds up to `bulkRefreshBatchSize` (2000) of them. */
    private const LOAD_CHUNK = 100;

    public function execute($queue): void
    {
        $total = count($this->items);
        self::refresh($this->items, fn(int $done) => $this->setProgress($queue, $done / $total, "$done/$total"));
    }

    /**
     * Refreshes the items' elements in every one of their sites given.
     * @param list<array{elementId: int|string, siteId: int|string}> $items
     * @param callable(int): void $progress called with the number of items done so far
     */
    public static function refresh(array $items, callable $progress): void
    {
        $done = 0;
        ExtractorService::inBatch(function () use ($items, $progress, &$done): void {
            foreach (array_chunk($items, self::LOAD_CHUNK) as $chunk) {
                $loaded = self::load($chunk);
                foreach ($chunk as $item) {
                    $element = $loaded[(int)$item['elementId'] . ':' . (int)$item['siteId']]
                        ?? self::trashed((int)$item['elementId'], (int)$item['siteId']);
                    if ($element) {
                        ExtractorService::refreshRelationsForElement($element);
                    }
                    $progress(++$done);
                }
            }
        });
    }

    /**
     * The items' elements as getElementById() loads them (any status, drafts and provisional drafts included), with one
     * query per element type and site instead of two per element: loading was most of a rebuild's time.
     * @param list<array{elementId: int|string, siteId: int|string}> $items
     * @return array<string, ElementInterface> keyed `<elementId>:<siteId>`
     */
    private static function load(array $items): array
    {
        $elementIds = array_values(array_unique(array_map(fn(array $item): int => (int)$item['elementId'], $items)));
        /** @var array<int|string, string> $types */
        $types = (new \craft\db\Query())->select(['type'])->from(\craft\db\Table::ELEMENTS)->where(['id' => $elementIds])->indexBy('id')->column();
        $idsByTypeAndSite = [];
        foreach ($items as $item) {
            $type = $types[(int)$item['elementId']] ?? null;
            if ($type !== null && is_subclass_of($type, ElementInterface::class)) {
                $idsByTypeAndSite[$type][(int)$item['siteId']][] = (int)$item['elementId'];
            }
        }
        $loaded = [];
        foreach ($idsByTypeAndSite as $type => $idsBySite) {
            foreach ($idsBySite as $siteId => $ids) {
                $query = Craft::$app->getElements()->createElementQuery($type)->id($ids)->siteId($siteId)->status(null)->drafts(null)->provisionalDrafts(null)->revisions(null);
                foreach ($query->all() as $element) {
                    if ($element instanceof ElementInterface) {
                        $loaded[$element->id . ':' . $siteId] = $element;
                    }
                }
            }
        }
        return $loaded;
    }

    /**
     * A trashed element, which keeps its usages, unless it is a nested element removed on its own (a Matrix block
     * taken out of its field, not trashed with its owner): its usages went with it.
     */
    private static function trashed(int $elementId, int $siteId): ?ElementInterface
    {
        $type = Craft::$app->getElements()->getElementTypeById($elementId);
        if ($type === null || !is_subclass_of($type, ElementInterface::class)) {
            return null;
        }
        $element = \internetztube\elementRelations\ElementRelations::find($type)->id($elementId)->siteId($siteId)->status(null)->drafts(null)->provisionalDrafts(null)->trashed(true)->one();
        if (!$element instanceof ElementInterface) {
            return null;
        }
        $nested = $element instanceof NestedElementInterface && $element->getPrimaryOwnerId() !== null;
        return $nested && !$element->deletedWithOwner ? null : $element;
    }

    protected function defaultDescription(): ?string
    {
        return "Resave Element Relations";
    }
}
