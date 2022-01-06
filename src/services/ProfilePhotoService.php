<?php

namespace internetztube\elementRelations\services;

use craft\db\Query;
use craft\db\Table;

class ProfilePhotoService
{
    public const IDENTIFIER_PROFILE_PHOTO_START = 'profile-picture-start';
    public const IDENTIFIER_PROFILE_PHOTO_END = 'profile-picture-end';

    /**
     * Checks where an Asset is used as an profile picture.
     * @param int $assetId
     * @return int[]
     */
    public static function getAssetUsageInProfilePhotos(int $assetId): array
    {
        return (new Query())
            ->select('id')
            ->from(Table::USERS)
            ->where(['photoId' => $assetId])
            ->column();
    }
}