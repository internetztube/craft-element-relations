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

        return (new Query())
            ->select("elements_sites.*")
            ->from(["users" => Table::USERS])
            ->innerJoin(
                ["elements_sites" => Table::ELEMENTS_SITES],
                "[[elements_sites.elementId]] = [[users.id]]"
            )
            ->where(["users.photoId" => $element->id]);
    }
}