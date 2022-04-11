<?php

namespace internetztube\elementRelations\services;

use Craft;

class LinkItService
{
    /**
     * @param int $elementId
     * @return array
     *
     * <code>
     * [
     *   ['elementId' => int, 'siteId' => int]
     * ]
     * </code>
     */
    public static function getLinkItRelations(int $elementId): array
    {
        $likeStatement = sprintf('%%"value":"%d"%%', $elementId);
        return ElementRelationsService::getFilledContentRowsByFieldType(\fruitstudios\linkit\fields\LinkitField::class, [$likeStatement]);
    }

    /**
     * @param int $elementId
     * @return int[]
     */
    public static function getLinkItRelationsUsedInElement(int $elementId): array
    {
        $element = ElementRelationsService::getElementById($elementId);
        if (!$element) {
            return [];
        }
        return collect($element->getFieldValues())->filter(function ($value) {
            return $value instanceof \fruitstudios\linkit\base\ElementLink;
        })->map(function (\fruitstudios\linkit\base\ElementLink $value) {
            return (int)$value->value;
        })->flatten()->all();
    }

    /**
     * Is the LinkIt Plugin installed and enabled?
     * @return bool
     */
    public static function isLinkItEnabled(): bool
    {
        return Craft::$app->plugins->isPluginEnabled('linkit');
    }
}