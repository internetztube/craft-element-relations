<?php

use craft\elements\Entry;

return function (array $ctx): void {
    $entry = Entry::find()->title('Page via Craft Link')->one();
    $link = $entry?->getFieldValue('craftLink');
    check($link instanceof craft\fields\data\LinkData && $link->getElement()?->id === $ctx['target']->id, 'Craft Link field: value round-trips as LinkData to Page C');
    checkRelation($ctx['target'], 'Page via Craft Link', 'Craft Link field');
    checkRelation($ctx['asset'], 'Page via Craft Link (asset)', 'Craft Link field (asset)');

    $pointsTo = fn($e, $t) => $e->getFieldValue('craftLink')?->getElement()?->id === $t->id;
    replaceRoundTrip($entry, $ctx['target'], 'Craft Link entry', $pointsTo);
    replaceRoundTrip(Entry::find()->title('Page via Craft Link (asset)')->one(), $ctx['asset'], 'Craft Link asset', $pointsTo);
};
