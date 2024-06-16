<?php

namespace internetztube\elementRelations\jobs;

use Craft;
use craft\base\ElementInterface;
use craft\queue\BaseJob;

/**
 * Resave Single Element Relations queue job
 */
class ResaveSingleElementRelations extends BaseJob
{
    public ElementInterface $element;

    function execute($queue): void
    {
        dd($this->element);
    }

    protected function defaultDescription(): ?string
    {
        return "Resave Element Relation";
    }
}
