<?php
/**
 * User photo (core): the admin's photo is the test asset. The admin is the only user that exists in every edition
 * (Craft Solo has no user management), and the relation chip links to "My Account".
 */
return function (array $ctx): void {
    $elements = Craft::$app->getElements();
    // earlier seeds created an extra "photouser" (invisible in Solo); drop it
    if ($old = \craft\elements\User::find()->username('photouser')->status(null)->one()) {
        $elements->deleteElement($old, true);
    }
    // Craft keeps user photos in the configured "user photo volume"; point it at the test volume
    $projectConfig = Craft::$app->getProjectConfig();
    if (!$projectConfig->get('users.photoVolumeUid')) {
        $projectConfig->set('users.photoVolumeUid', Craft::$app->getVolumes()->getVolumeByHandle('test')->uid);
    }
    $admin = \craft\elements\User::find()->admin()->one();
    if ($admin->photoId !== $ctx['asset']->id) {
        $admin->setPhoto($ctx['asset']);
        $elements->saveElement($admin) or fail($admin);
    }
};
