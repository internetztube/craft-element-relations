<?php

namespace internetztube\elementRelations\services\fields;

use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;

class FieldRelationsService implements InterfaceFieldService
{
    public static function getElementsSitesQuery(ElementInterface $element): Query
    {
        $tableRelations = Table::RELATIONS;
        $tableElementsSites = Table::ELEMENTS_SITES;

        return (new Query())
            ->select("$tableElementsSites.*")
            ->from($tableRelations)
            ->innerJoin($tableElementsSites, "
                (
                    ($tableRelations.sourceId = $tableElementsSites.elementId AND $tableRelations.sourceSiteId IS NULL) OR 
                    ($tableRelations.sourceId = $tableElementsSites.elementId AND $tableRelations.sourceSiteId = $tableElementsSites.siteId)
                )
            ")
            ->where(['and', ['=', "$tableRelations.targetId", $element->id]]);
    }
}