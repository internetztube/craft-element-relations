<?php

namespace internetztube\elementRelations\services\fields;

use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;

class FieldUserPhotoService implements InterfaceFieldService
{
    public static function getElementsSitesQuery(ElementInterface $element): ?Query
    {
        if (!($element instanceof Asset)) {
            return null;
        }
        $tableElementsSites = Table::ELEMENTS_SITES;
        $tableElements = Table::ELEMENTS;;
        $tableUsers = Table::USERS;

        return (new Query())
            ->select("$tableElementsSites.*")
            ->from(Table::USERS)
            ->innerJoin($tableElementsSites,  "$tableElementsSites.elementId = $tableUsers.id")
            ->innerJoin($tableElements, "$tableElementsSites.elementId = $tableElements.id")
            ->where(["$tableUsers.photoId" => $element->id]);
    }
}