<?php

use craft\db\Query;
use craft\elements\Entry;

return function (array $ctx): void {
    $rows = fn(string $title) => (new Query())->select(['targetElementId'])->from('{{%elementrelations_cache}}')
        ->where(['sourceElementId' => Entry::find()->title($title)->one()?->id])->column();

    $entry = Entry::find()->title('Page via LinkMate')->one();
    $link = $entry?->getFieldValue('linkMate');
    check($link instanceof vaersaagod\linkmate\models\Link && $link->getElement(true)?->id === $ctx['target']->id, 'LinkMate: value round-trips as Link to Page C');
    checkRelation($ctx['target'], 'Page via LinkMate', 'LinkMate');
    check($rows('Page via LinkMate (url)') === [], 'LinkMate: URL link relates to nothing');

    replaceRoundTrip($entry, $ctx['target'], 'LinkMate entry link', fn($e, $t) => $e->getFieldValue('linkMate')->type === 'entry' && (int)$e->getFieldValue('linkMate')->value === $t->id);
};
