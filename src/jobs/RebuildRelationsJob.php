<?php

declare(strict_types=1);

namespace internetztube\elementRelations\jobs;

use Craft;
use craft\queue\BaseJob;
use internetztube\elementRelations\ElementRelations;

/**
 * One lane of a full rebuild of the relations cache. GenerateResaveAllElementRelationsJobsJob splits the elements into
 * `rebuildParallelJobs` slices and queues one of these per slice; each refreshes the next `bulkRefreshBatchSize`
 * elements of its slice and then queues itself again for the rest. So a rebuild keeps as many jobs in the queue as run
 * in parallel, not one per batch from the start: other jobs get in between, the queue lists the lanes with their
 * progress, and releasing a lane stops it.
 *
 * A lane goes on by position (element id, site id), never by offset: each batch is one index range scan, however far it
 * got. A lane whose worker died runs again from the same position (refreshing is idempotent).
 */
class RebuildRelationsJob extends BaseJob
{
    /** this lane's number, of `lanes` (for the description) */
    public int $lane = 1;
    public int $lanes = 1;
    /** its slice: element ids above `fromId`, up to and including `toId` */
    public int $fromId = 0;
    public int $toId = 0;
    /** where the previous batch ended */
    public int $afterElementId = 0;
    public int $afterSiteId = 0;
    /** elements (in every site) done so far, of `total` in the slice */
    public int $done = 0;
    public int $total = 0;

    public function execute($queue): void
    {
        $batchSize = ElementRelations::$plugin->getSettings()->bulkRefreshBatchSize;
        /** @var list<array{elementId: int|string, siteId: int|string}> $items */
        $items = GenerateResaveAllElementRelationsJobsJob::query()
            ->andWhere(['>', 'elements_sites.elementId', $this->fromId])
            ->andWhere(['<=', 'elements_sites.elementId', $this->toId])
            ->andWhere(['or', ['>', 'elements_sites.elementId', $this->afterElementId], ['and', ['elements_sites.elementId' => $this->afterElementId], ['>', 'elements_sites.siteId', $this->afterSiteId]]])
            ->orderBy(['elements_sites.elementId' => SORT_ASC, 'elements_sites.siteId' => SORT_ASC])
            ->limit($batchSize)
            ->all();
        ResaveElementRelationsJob::refresh($items, fn(int $done) => $this->setProgress($queue, min(1, ($this->done + $done) / max(1, $this->total)), ($this->done + $done) . '/' . $this->total));
        $last = end($items);
        if ($last && count($items) === $batchSize) {
            // the rest of the slice: the same lane, from here
            Craft::$app->getQueue()->push(new self(['afterElementId' => (int)$last['elementId'], 'afterSiteId' => (int)$last['siteId'], 'done' => $this->done + count($items)] + array_intersect_key(get_object_vars($this), array_flip(['lane', 'lanes', 'fromId', 'toId', 'total']))));
        }
    }

    protected function defaultDescription(): ?string
    {
        return "Rebuild Element Relations (part $this->lane of $this->lanes)";
    }
}
