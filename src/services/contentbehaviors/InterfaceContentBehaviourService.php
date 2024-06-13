<?php

namespace internetztube\elementRelations\services\contentbehaviors;


use craft\db\Query;

interface InterfaceContentBehaviourService
{
    public static function enrichQuery(Query $query): Query;

    public static function getColumns(): array;
    public static function getColumnElementsType(): string;
    public static function getColumnElementsId(): string;
}