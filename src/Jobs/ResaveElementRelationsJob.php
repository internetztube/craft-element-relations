<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Jobs;

use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Contracts\NestedElementInterface;
use CraftCms\Cms\Queue\Job;
use Illuminate\Support\Facades\DB;
use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Services\ExtractorService;
use Override;

/** Refreshes the relations cache of a batch of elements (QueueElementsForRefreshService, full rebuilds). */
class ResaveElementRelationsJob extends Job
{
    /** Items loaded per element query: a job holds up to `bulkRefreshBatchSize` (2000) of them. */
    private const LOAD_CHUNK = 100;

    /** @param list<array{elementId: int|string, siteId: int|string}> $items ids are strings when they come from a MySQL query */
    public function __construct(public array $items = [], public ?string $label = null)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        $total = count($this->items);
        $done = 0;
        ExtractorService::inBatch(function () use ($total, &$done): void {
            foreach (array_chunk($this->items, self::LOAD_CHUNK) as $chunk) {
                $loaded = self::load($chunk);
                foreach ($chunk as $item) {
                    $element = $loaded[(int)$item['elementId'] . ':' . (int)$item['siteId']]
                        ?? self::trashed((int)$item['elementId'], (int)$item['siteId']);
                    if ($element) {
                        ExtractorService::refreshRelationsForElement($element);
                    }
                    $done++;
                    $this->setProgress(max(0, min(100, intdiv($done * 100, max(1, $total)))), "$done/$total");
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
        /** @var array<int, string> $types */
        $types = DB::table(Table::ELEMENTS)->whereIntegerInRaw('id', $elementIds)->pluck('type', 'id')->all();
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
                foreach (ElementRelations::find($type)->id($ids)->siteId($siteId)->status(null)->drafts(null)->provisionalDrafts(null)->revisions(null)->all() as $element) {
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
        $type = DB::table(Table::ELEMENTS)->where('id', $elementId)->value('type');
        if (!is_string($type) || !is_subclass_of($type, ElementInterface::class)) {
            return null;
        }
        $element = ElementRelations::find($type)->id($elementId)->siteId($siteId)->status(null)->drafts(null)->provisionalDrafts(null)->trashed(true)->one();
        if (!$element instanceof ElementInterface) {
            return null;
        }
        $nested = $element instanceof NestedElementInterface && $element->getPrimaryOwnerId() !== null;
        return $nested && !$element->deletedWithOwner ? null : $element;
    }

    #[Override]
    protected function defaultDescription(): string
    {
        return $this->label ?? 'Resave Element Relations';
    }
}
