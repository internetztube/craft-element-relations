<?php

declare(strict_types=1);

namespace internetztube\elementRelations\jobs;

use Craft;
use craft\queue\BaseJob;
use internetztube\elementRelations\services\ExtractorService;

/** Refreshes the relations cache of a batch of elements (QueueElementsForRefreshService, full rebuilds). */
class ResaveElementRelationsJob extends BaseJob
{
    /** @var list<array{elementId: int|string, siteId: int|string}> ids are strings when they come from a MySQL query */
    public array $items = [];

    public function execute($queue): void
    {
        $total = count($this->items);
        foreach ($this->items as $index => $item) {
            $element = Craft::$app->getElements()->getElementById((int)$item['elementId'], null, (int)$item['siteId']);
            if ($element) {
                ExtractorService::refreshRelationsForElement($element);
            }
            $this->setProgress($queue, ($index + 1) / $total, ($index + 1) . "/$total");
        }
    }

    protected function defaultDescription(): ?string
    {
        return "Resave Element Relations";
    }
}
