<?php

use craft\elements\Asset;
use craft\elements\Entry;
use internetztube\elementRelations\services\ReplaceService;
use verbb\navigation\elements\Node;
use verbb\navigation\Navigation;

return function (array $ctx): void {
    checkRelation($ctx['target'], 'Main', 'Navigation entry node (shown as its menu)');
    checkRelation($ctx['asset'], 'Main', 'Navigation asset node (shown as its menu)');

    // Replace: the field lists the menu, the node is what gets rewritten (elementId + linked element site).
    $menu = Navigation::$plugin->getMenus()->getMenuByHandle('main');
    $elements = Craft::$app->getElements();
    $roundTrip = function (string $title, $from, $target) use ($menu, $elements): void {
        $node = fn() => Node::find()->menuId($menu->id)->title($title)->status(null)->one();
        $result = ReplaceService::replace($from, $target, [$menu->id]);
        flushQueue();
        check($result['replaced'] === 1 && !$result['skipped'], "$title: replace rewrites the node (" . json_encode($result) . ')');
        check(in_array('Main', relatingTitles($target), true) && !in_array('Main', relatingTitles($from), true), "$title: the relation moved to the target");
        check($node()->getElement()?->id === $target->id, "$title: the node links the target");
        $back = ReplaceService::replace($target, $from, [$menu->id]);
        flushQueue();
        check($back['replaced'] === 1 && in_array('Main', relatingTitles($from), true) && $node()->getElement()?->id === $from->id, "$title: replacing back restores the original");
        $elements->deleteElement($target, true);
    };

    $from = $ctx['target'];
    $entry = new Entry(['sectionId' => $from->sectionId, 'typeId' => $from->typeId, 'title' => 'RT Navigation entry']);
    $elements->saveElement($entry) or throw new RuntimeException(json_encode($entry->getErrors()));
    $roundTrip('Page C node', $from, $entry);

    $tmp = tempnam(sys_get_temp_dir(), 'er') . '.png';
    file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $asset = new Asset(['tempFilePath' => $tmp, 'filename' => 'rt-nav.png', 'title' => 'RT Navigation asset', 'newFolderId' => $ctx['asset']->folderId, 'volumeId' => $ctx['asset']->volumeId, 'avoidFilenameConflicts' => true]);
    $asset->setScenario(Asset::SCENARIO_CREATE);
    $elements->saveElement($asset) or throw new RuntimeException(json_encode($asset->getErrors()));
    $roundTrip('Asset node', $ctx['asset'], $asset);
};
