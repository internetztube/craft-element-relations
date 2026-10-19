<?php

use benf\neo\elements\Block;
use craft\elements\Entry;
use internetztube\elementRelations\models\RelationsModel;

return function (array $ctx): void {
    if (!Craft::$app->getPlugins()->isPluginEnabled('neo')) {
        echo "  (neo not installed, skipped)\n";
        return;
    }
    checkRelation($ctx['target'], 'Page via Neo', 'Neo block');
    $titles = relatingTitles($ctx['target']);
    check(count(array_keys($titles, 'Page via Neo', true)) === 1, 'owner is listed exactly once');

    $owner = Entry::find()->title('Page via Neo')->one();
    $block = Block::find()->ownerId($owner->id)->status(null)->one();
    $sourceIds = (new RelationsModel($ctx['target']->id, $ctx['target']->siteId))->getSourceElementIds([]);
    check($block !== null && !in_array($block->id, $sourceIds, true), 'block itself is not a source (only the owner)');
};
