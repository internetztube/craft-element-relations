<?php

namespace internetztube\elementRelations\services;

use fruitstudios\linkit\fields\LinkitField;

class LinkItService
{
    public static function getLinkItRelations(int $elementId): array
    {
        $likeStatement = sprintf('%%"value":"%d"%%', $elementId);
        return ElementRelationsService::getFilledContentRowsByFieldType(LinkitField::class, $likeStatement);
    }

    public static function getLinkItRelationsUsedInElement(int $elementId): array
    {
//        TODO
//        $element = ElementRelationsService::getElementById($elementId);
//        if (!$element) {
//            return [];
//        }
//        return collect($element->getFieldValues())->filter(function ($value) {
//            return $value instanceof LinkitField;
//        })->map(function (LinkitField $value) {
//            dd($value);
//            return collect($exploded)->filter(function ($item) {
//                return is_numeric($item) && (string)((int)$item) === (string)$item;
//            })->map(function ($item) {
//                return (int)$item;
//            })->values();
//        })->flatten()->all();
    }
}