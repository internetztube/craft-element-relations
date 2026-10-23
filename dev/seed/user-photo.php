<?php

declare(strict_types=1);

/**
 * User photo (core): the admin's photo is the test asset. The admin is the only user that exists in every edition
 * (Craft Solo has no user management), and the relation chip links to "My Account".
 */

use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\ProjectConfig;
use CraftCms\Cms\Support\Facades\Volumes;
use CraftCms\Cms\User\Elements\User;

return function (array $ctx): void {
    // earlier seeds created an extra "photouser" (invisible in Solo); drop it
    if ($old = User::find()->username('photouser')->status(null)->one()) {
        Elements::deleteElement($old, true);
    }
    // Craft keeps user photos in the configured "user photo volume"; point it at the test volume
    if (!ProjectConfig::get('users.photoVolumeUid')) {
        ProjectConfig::set('users.photoVolumeUid', Volumes::getVolumeByHandle('test')?->uid);
        ProjectConfig::saveModifiedConfigData(); // a script never terminates the app, where Craft writes it
    }
    $admin = User::find()->username('admin')->one() ?? throw new RuntimeException('admin');
    if ($admin->photoId !== $ctx['asset']->id) {
        $admin->setPhoto($ctx['asset']);
        Elements::saveElement($admin) or fail($admin);
    }
};
