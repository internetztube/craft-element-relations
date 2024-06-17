<?php

namespace internetztube\elementRelations\jobs;

use Craft;
use craft\queue\BaseJob;
use internetztube\elementRelations\services\ResaveRelationsService;

/**
 * Resave Element Relations Job queue job
 */
class ResaveAllElementRelationsJob extends BaseJob
{
    function execute($queue): void
    {
        ResaveRelationsService::resave(function ($index, $totalCount, $elementType) use ($queue) {
            $this->setProgress($queue, $index / $totalCount, "$index/$totalCount");
        });
    }
    protected function defaultDescription(): ?string
    {
        return "Resave All Element Relations";
    }
}
