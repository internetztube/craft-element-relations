<?php

namespace internetztube\elementRelations\utilities;

use Craft;
use craft\base\Utility;
use internetztube\elementRelations\jobs\ResaveAllElementRelationsJob;

/**
 * Element Relations utility
 */
class ElementRelationsUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t("element-relations", "Element Relations");
    }

    static function id(): string
    {
        return "element-relations";
    }

    public static function icon(): ?string
    {
        return Craft::getAlias("@internetztube/elementRelations/icon-mask.svg");
    }

    static function contentHtml(): string
    {
        $pushed = false;
        if (Craft::$app->request->getIsPost()) {
            Craft::$app->getQueue()->push(new ResaveAllElementRelationsJob());
            $pushed = true;
        }
        return Craft::$app->getView()->renderTemplate("element-relations/_utility", ["pushed" => $pushed]);
    }
}
