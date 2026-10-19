<?php

use craft\elements\Entry;

return function (array $ctx): void {
    $link = Entry::find()->title('Page via Craft Link')->one()?->getFieldValue('craftLink');
    check($link instanceof craft\fields\data\LinkData && $link->getElement()?->id === $ctx['target']->id, 'Craft Link field: value round-trips as LinkData to Page C');
    checkRelation($ctx['target'], 'Page via Craft Link', 'Craft Link field');
    checkRelation($ctx['asset'], 'Page via Craft Link (asset)', 'Craft Link field (asset)');
};
