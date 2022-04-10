<?php

namespace internetztube\elementRelations\services;

use Craft;

class RedactorService
{
    /**
     * In which element is a certain other element in it's redactor content.
     * For example, if you want to know in which Entries an Asset is used, then you this method is for you.
     * @param int $elementId
     * @return array
     *
     * <code>
     * [
     *   ['elementId' => int, 'siteId' => int]
     * ]
     * </code>
     */
    public static function getRedactorRelations(int $elementId): array
    {
        $likeStatements = [
            sprintf('%%:%s:%%', $elementId),
            sprintf('%%:%s@%%:%%', $elementId),
        ];
        return ElementRelationsService::getFilledContentRowsByFieldType(\craft\redactor\Field::class, $likeStatements);
    }

    /**
     * Which elements are used in a certain element.
     * For example, if you want to know which Assets are used in an Entry, then you this method is for you.
     * @param int $elementId
     * @return int[]
     */
    public static function getRedactorRelationsUsedInElement(int $elementId): array
    {
        $element = ElementRelationsService::getElementById($elementId);
        if (!$element) {
            return [];
        }
        return collect($element->getFieldValues())->filter(function ($value) {
            return $value instanceof \craft\redactor\FieldData;
        })->map(function (\craft\redactor\FieldData $value) {
            $exploded = explode(':', $value->getRawContent());
            return collect($exploded)->filter(function ($item) {
                return is_numeric($item) && (string)((int)$item) === (string)$item;
            })->map(function ($item) {
                return (int)$item;
            })->values();
        })->flatten()->all();
    }

    /**
     * Is the Redactor Plugin installed and enabled?
     * @return bool
     */
    public static function isRedactorEnabled(): bool
    {
        return Craft::$app->plugins->isPluginEnabled('redactor');
    }
}