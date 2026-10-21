<?php

use craft\elements\Entry;

return function (array $ctx): void {
    checkRelation($ctx['target'], 'Page via LinkIt', 'LinkIt');

    $entry = Entry::find()->title('Page via LinkIt')->one();
    replaceRoundTrip($entry, $ctx['target'], 'LinkIt entry link', fn($e, $t) => (int)$e->getFieldValue('linkitLink')->value === $t->id);
};
