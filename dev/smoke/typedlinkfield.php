<?php

use craft\elements\Entry;

return function (array $ctx): void {
    $entry = Entry::find()->title('Page via Typed Link')->one();
    $link = $entry?->getFieldValue('typedLink');
    check($link instanceof lenz\linkfield\models\element\ElementLink && $link->getElement(true)?->id === $ctx['target']->id, 'Typed Link Field: value round-trips as an ElementLink to Page C');
    checkRelation($ctx['target'], 'Page via Typed Link', 'Typed Link Field');

    replaceRoundTrip($entry, $ctx['target'], 'Typed Link Field entry link', fn($e, $t) => $e->getFieldValue('typedLink')->linkedId === $t->id && $e->getFieldValue('typedLink')->getElement(true)?->id === $t->id);
};
