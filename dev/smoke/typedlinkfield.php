<?php

use craft\elements\Entry;

return function (array $ctx): void {
    $link = Entry::find()->title('Page via Typed Link')->one()?->getFieldValue('typedLink');
    check($link instanceof lenz\linkfield\models\element\ElementLink && $link->getElement(true)?->id === $ctx['target']->id, 'Typed Link Field: value round-trips as an ElementLink to Page C');
    checkRelation($ctx['target'], 'Page via Typed Link', 'Typed Link Field');
};
