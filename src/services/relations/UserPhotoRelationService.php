<?php

namespace internetztube\elementRelations\services\relations;

use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;

class UserPhotoRelationService
{
    /**
     * @param Asset $asset
     * @return ElementInterface[]
     */
    public static function getReverseRelations(Asset $asset): array
    {
        $tableElementsSites = Table::ELEMENTS_SITES;
        $tableElements = Table::ELEMENTS;;
        $tableUsers = Table::USERS;

        $baseElementInfo = (new Query())
            ->select([
                "$tableElementsSites.elementId",
                "$tableElementsSites.siteId",
                "$tableElements.type"
            ])
            ->from(Table::USERS)
            ->innerJoin($tableElementsSites,  "$tableElementsSites.elementId = $tableUsers.id")
            ->innerJoin($tableElements, "$tableElementsSites.elementId = $tableElements.id")
            ->where(["$tableUsers.photoId" => $asset->id])
            ->all();

        return BaseRelationsService::getElementsByBaseElementInfo($baseElementInfo);
    }
}