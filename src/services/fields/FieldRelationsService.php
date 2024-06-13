<?php

namespace internetztube\elementRelations\services\fields;

use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;

class FieldRelationsService implements InterfaceFieldService
{
    public static function getElementsSitesQuery(ElementInterface $element): Query
    {
        return (new Query())
            ->select("elements_sites.*")
            ->from(["relations" => Table::RELATIONS])
            ->innerJoin(["elements_sites" => Table::ELEMENTS_SITES], [
                "or",
                [
                    "and",
                    "[[relations.sourceId]] = [[elements_sites.elementId]]",
                    "[[relations.sourceSiteId]] IS NULL"
                ],
                [
                    "and",
                    "[[relations.sourceId]] = [[elements_sites.elementId]]",
                    "[[relations.sourceSiteId]] = [[elements_sites.siteId]]"
                ]
            ])
            ->where(['and', ['=', "relations.targetId", $element->id]]);
    }
}