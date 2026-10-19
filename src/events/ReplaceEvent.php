<?php

declare(strict_types=1);

namespace internetztube\elementRelations\events;

use craft\base\ElementInterface;
use craft\events\CancelableEvent;
use internetztube\elementRelations\records\ReplacementRecord;

/**
 * Fired around Pro replacements and rollbacks (see services\ReplaceService). Set `$isValid = false` in a
 * `beforeReplace` handler to stop the replacement.
 *
 * @phpstan-import-type ReplaceResult from \internetztube\elementRelations\services\ReplaceService
 * @phpstan-import-type RollbackResult from \internetztube\elementRelations\services\ReplaceService
 */
class ReplaceEvent extends CancelableEvent
{
    public ElementInterface $from;
    public ElementInterface $to;
    /** @var int[]|null owner ids the replacement is limited to; null = everywhere */
    public ?array $sourceIds = null;
    /** @var ReplaceResult|RollbackResult|null set on the "after" events */
    public ?array $result = null;
    public ?ReplacementRecord $operation = null;
}
