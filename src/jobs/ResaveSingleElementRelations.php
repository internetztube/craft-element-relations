<?php

namespace internetztube\elementRelations\jobs;

use Craft;
use craft\base\ElementInterface;
use craft\queue\BaseJob;
use internetztube\elementRelations\services\ExtractorService;

/**
 * Resave Single Element Relations queue job
 */
class ResaveSingleElementRelations extends BaseJob
{
    public int $elementId;
    public int $siteId;

    function execute($queue): void
    {
        $element = Craft::$app->getElements()->getElementById($this->elementId, null, $this->siteId);
        if ($element) {
            ExtractorService::refreshRelationsForElement($element);
        }
    }

    protected function defaultDescription(): ?string
    {
        return "Resave Single Element Relation";
    }
}
