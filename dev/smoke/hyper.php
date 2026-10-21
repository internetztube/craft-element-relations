<?php

use craft\elements\Entry;

return function (array $ctx): void {
    checkRelation($ctx['target'], 'Page via Hyper', 'Hyper');
    checkRelation($ctx['asset'], 'Page via Hyper', 'Hyper (asset link)');

    $entry = Entry::find()->title('Page via Hyper')->one();
    // element ids of all links in order: the replaced one changes, the other one stays
    $linkIds = fn($e) => array_map(fn($l) => $l instanceof verbb\hyper\base\ElementLink ? $l->getElement(null)?->id : null, $e->getFieldValue('hyperLink')->getLinks());
    replaceRoundTrip($entry, $ctx['target'], 'Hyper entry link', fn($e, $t) => $linkIds($e) === [$t->id, $ctx['asset']->id]);
    replaceRoundTrip($entry, $ctx['asset'], 'Hyper asset link', fn($e, $t) => $linkIds($e) === [$ctx['target']->id, $t->id]);
};
