<?php
/** Navigation (verbb): menu "Main" with an Entry node linking Page C and an Asset node linking the test asset. */
return function (array $ctx): void {
    $menus = \verbb\navigation\Navigation::$plugin->getMenus();
    if (!$menu = $menus->getMenuByHandle('main')) {
        $menu = new \verbb\navigation\models\MenuSettings(['name' => 'Main', 'handle' => 'main']);
        $menu->setSiteSettings([new \verbb\navigation\models\MenuSiteSettings(['siteId' => $ctx['siteId'], 'enabled' => true])]);
        $menus->saveMenu($menu) or fail($menu);
    }
    $nodes = [
        'Page C node' => [\verbb\navigation\nodetypes\Entry::class, $ctx['target']],
        'Asset node' => [\verbb\navigation\nodetypes\Asset::class, $ctx['asset']],
    ];
    foreach ($nodes as $title => [$type, $linked]) {
        if (\verbb\navigation\elements\Node::find()->menuId($menu->id)->title($title)->status(null)->exists()) {
            continue;
        }
        $node = new \verbb\navigation\elements\Node([
            'menuId' => $menu->id,
            'siteId' => $ctx['siteId'],
            'type' => $type,
            'elementId' => $linked->id,
            'title' => $title,
        ]);
        Craft::$app->getElements()->saveElement($node) or fail($node);
    }
};
