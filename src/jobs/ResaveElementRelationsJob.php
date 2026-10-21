<?php

declare(strict_types=1);

namespace internetztube\elementRelations\jobs;

use Craft;
use craft\base\NestedElementInterface;
use craft\base\ElementInterface;
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
            $element = Craft::$app->getElements()->getElementById((int)$item['elementId'], null, (int)$item['siteId'])
                ?? self::trashed((int)$item['elementId'], (int)$item['siteId']);
            if ($element) {
                ExtractorService::refreshRelationsForElement($element);
            }
            $this->setProgress($queue, ($index + 1) / $total, ($index + 1) . "/$total");
        }
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
        $element = $type::find()->id($elementId)->siteId($siteId)->status(null)->drafts(null)->provisionalDrafts(null)->trashed(true)->one();
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
