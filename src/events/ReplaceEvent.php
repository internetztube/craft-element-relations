<?php

namespace internetztube\elementRelations\events;

use craft\base\ElementInterface;
use craft\events\CancelableEvent;
use internetztube\elementRelations\records\ReplacementRecord;

/**
 * Fired around Pro replacements and rollbacks (see services\ReplaceService). Set `$isValid = false` in a
 * `beforeReplace` handler to stop the replacement.
 */
class ReplaceEvent extends CancelableEvent
{
    public ElementInterface $from;
    public ElementInterface $to;
    /** @var int[]|null owner ids the replacement is limited to; null = everywhere */
    public ?array $sourceIds = null;
    /** @var array{replaced?: int, skipped?: string[], restored?: int}|null set on the "after" events */
    public ?array $result = null;
    public ?ReplacementRecord $operation = null;
}
