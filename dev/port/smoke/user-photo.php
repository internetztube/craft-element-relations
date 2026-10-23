<?php

use craft\elements\User;

return function (array $ctx): void {
    $admin = User::find()->username('admin')->one();
    checkRelation($ctx['asset'], (string)$admin, 'User photo (admin)');
    check(!User::find()->username('photouser')->status(null)->one(), 'no extra user left behind (Craft Solo shows only the admin)');

    replaceRoundTrip($admin, $ctx['asset'], 'User photo', fn(User $u, $t) => $u->photoId === $t->id);
};
