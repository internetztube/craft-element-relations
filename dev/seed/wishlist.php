<?php
/** Wishlist (verbb): a list "Admin wishlist" of the admin user with an item for Page C. */
return function (array $ctx): void {
    $admin = \craft\elements\User::find()->username('admin')->one();
    if (!$list = \verbb\wishlist\elements\ListElement::find()->title('Admin wishlist')->status(null)->one()) {
        $list = new \verbb\wishlist\elements\ListElement([
            'title' => 'Admin wishlist',
            'userId' => $admin->id,
            // Lists::createList() needs a web session, so build the list by hand.
            'typeId' => \verbb\wishlist\Wishlist::$plugin->getListTypes()->getDefaultListType()->id,
            'reference' => \verbb\wishlist\Wishlist::$plugin->getLists()->generateReferenceNumber(),
        ]);
        Craft::$app->getElements()->saveElement($list) or fail($list);
    }
    if (!$list->getItem($ctx['target'])) {
        $item = \verbb\wishlist\Wishlist::$plugin->getItems()->createItem($list, $ctx['target']);
        Craft::$app->getElements()->saveElement($item) or fail($item);
    }
};
