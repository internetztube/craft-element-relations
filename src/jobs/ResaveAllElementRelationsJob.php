<?php

namespace internetztube\elementRelations\jobs;

use Craft;
use craft\helpers\Db;
use craft\queue\BaseJob;
use internetztube\elementRelations\services\ExtractorService;

class ResaveAllElementRelationsJob extends BaseJob
{
    public array $batch;

    function execute($queue): void
    {
        $totalCount = count($this->batch);
        foreach ($this->batch as $index => $row) {
            $element = \Craft::$app->getElements()->getElementById($row['elementId'], trim($row['type']), $row['siteId']);
            $this->setProgress($queue, $index / $totalCount, "$index/$totalCount");
            if (!$element) {
                continue;
            }
            ExtractorService::refreshRelationsForElement($element);
        }
    }
}
