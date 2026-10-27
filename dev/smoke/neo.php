<?php

use benf\neo\elements\Block;
use craft\elements\Entry;
use craft\db\Query;
use internetztube\elementRelations\models\RelationsModel;
use internetztube\elementRelations\services\ExtractorService;
use internetztube\elementRelations\services\PruneService;

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

    // a Neo field converted to Matrix keeps its blocks, owned as before, on a field that is no Neo field any more
    $db = Craft::$app->getDb();
    $neoFieldId = $block->fieldId;
    $rows = (new Query())->from('{{%elementrelations_cache}}')->where(['sourceElementId' => $block->id])->all();
    $convert = fn(int $fieldId) => $db->createCommand()->update('{{%neoblocks}}', ['fieldId' => $fieldId], ['id' => $block->id])->execute();
    $refresh = fn(): bool => ExtractorService::refreshRelationsForElement(Block::find()->id($block->id)->siteId($owner->siteId)->status(null)->one());
    $convert((int)Craft::$app->getFields()->getFieldByHandle('relatedEntries')->id);
    check($rows && $refresh() && !in_array('Page via Neo', relatingTitles($ctx['target']), true), 'a block left behind by a field converted to Matrix is no usage: its refresh drops its rows');
    foreach ([false, true] as $rebuild) {
        $db->createCommand()->batchInsert('{{%elementrelations_cache}}', array_keys($rows[0]), array_map(array_values(...), $rows))->execute();
        PruneService::prune(rebuild: $rebuild);
        check(!(new Query())->from('{{%elementrelations_cache}}')->where(['sourceElementId' => $block->id])->exists(), 'prune (' . ($rebuild ? 'rebuild' : 'batched deletes') . ') removes the rows of such a block');
    }
    $convert($neoFieldId);
    check($refresh() && in_array('Page via Neo', relatingTitles($ctx['target']), true), 'back on its Neo field, the block is a usage again');
};
