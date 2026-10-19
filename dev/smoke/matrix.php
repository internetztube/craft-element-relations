<?php

use craft\elements\Entry;
use internetztube\elementRelations\models\RelationsModel;

return function (array $ctx): void {
    checkRelation($ctx['target'], 'Page via Matrix', 'Matrix nested entry');
    $titles = relatingTitles($ctx['target']);
    check(count(array_keys($titles, 'Page via Matrix', true)) === 1, 'owner is listed exactly once');

    $owner = Entry::find()->title('Page via Matrix')->one();
    $nested = Entry::find()->type('teaser')->ownerId($owner->id)->status(null)->one();
    $sourceIds = (new RelationsModel($ctx['target']->id, $ctx['target']->siteId))->getSourceElementIds([]);
    check($nested !== null && !in_array($nested->id, $sourceIds, true), 'nested entry itself is not a source (only the owner)');

    replaceRoundTrip(\craft\elements\Entry::find()->title('Page via Matrix')->one(), $ctx['target'], 'Entries field inside a Matrix block', fn($e, $t) => array_map('intval', $e->getFieldValue('teasers')->one()->getFieldValue('relatedEntries')->ids()) === [$t->id]);
};
