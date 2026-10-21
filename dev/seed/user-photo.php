<?php
/** User photo (core): a user whose photo is the test asset. */
return function (array $ctx): void {
    if (\craft\elements\User::find()->username('photouser')->status(null)->one()) {
        return;
    }
    // Solo edition allows one user; lift the limit in memory only (not persisted).
    Craft::$app->edition = \craft\enums\CmsEdition::Pro;
    $user = new \craft\elements\User([
        'username' => 'photouser',
        'email' => 'photouser@example.com',
        'fullName' => 'Photo User',
    ]);
    $user->setPhoto($ctx['asset']);
    Craft::$app->getElements()->saveElement($user) or fail($user);
};
