<?php
return function (array $ctx): void {
    $admin = \craft\elements\User::find()->admin()->one();
    checkRelation($ctx['asset'], (string)$admin, 'User photo (admin)');
    check(!\craft\elements\User::find()->username('photouser')->status(null)->one(), 'no extra user left behind (Craft Solo shows only the admin)');
};
