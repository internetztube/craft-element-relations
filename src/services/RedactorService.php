<?php

namespace internetztube\elementRelations\services;

use Craft;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\db\Table;
use craft\fields\Matrix as MatrixField;
use craft\redactor\Field;
use craft\redactor\FieldData;
use verbb\supertable\fields\SuperTableField;

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
        $likeStatement = sprintf('%%:%s:%%', $elementId);
        return ElementRelationsService::getFilledContentRowsByFieldType(Field::class, $likeStatement);
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
            return $value instanceof FieldData;
        })->map(function (FieldData $value) {
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