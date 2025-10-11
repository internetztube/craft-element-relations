<?php

namespace internetztube\elementRelations\jobs;

use Craft;
use craft\queue\BaseJob;
use internetztube\elementRelations\services\ExtractorService;

class ResaveElementRelationsJob extends BaseJob
{
    public array $items = [];

    function execute($queue): void
    {
        $total = count($this->items);
        foreach ($this->items as $index => $item) {
            $element = Craft::$app->getElements()->getElementById($item['elementId'], null, $item['siteId']);
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
