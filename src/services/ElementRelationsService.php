<?php

namespace internetztube\elementRelations\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\fields\Matrix as MatrixField;
use internetztube\elementRelations\fields\ElementRelationsField;

class ElementRelationsService
{
    public const IDENTIFIER_ELEMENTS_START = 'elements-start-';
    public const IDENTIFIER_ELEMENTS_END = 'elements-end-';

    public const IDENTIFIER_DELIMITER = '|';

    /**
     * Get stringified element relations of an element. (uncached)
     * @param int $elementId
     * @return string
     */
    public static function getElementRelations(int $elementId): string
    {
        return 'dsf';
    }


    /**
     * Get all relations of an element that are stored in the `relations` table.
     * @param int $elementId
     * @param $siteId
     * @return array
     *
     * <code>
     * [
     *   ['elementId' => int, 'siteId' => int]
     * ]
     * </code>
     */
    private static function getElementRelationsFromElement(int $elementId, $siteId): array
    {
        $elements = (new Query())->select(['elements.id'])
            ->from(['relations' => Table::RELATIONS])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[relations.sourceId]] = [[elements.id]]')
            ->where(['relations.targetId' => $elementId])
            ->column();

        return collect($elements)->map(function (int $elementId) use ($siteId) {
            /** @var ?Element $element */
            $element = self::getElementById($elementId, $siteId);
            if (!$element) {
                return null;
            }
            return self::getRootElement($element, $siteId);
        })
            ->filter()
            ->unique(function (ElementInterface $element) {
                return sprintf('%s-%s', $element->id, $element->siteId);
            })
            ->values()
            ->map(function (ElementInterface $element) {
                return ['elementId' => $element->id, 'siteId' => $element->siteId];
            })
            ->all();
    }

    /**
     * Get an element by id with the corresponding Element Query Builder.
     * @param int $elementId
     * @param null $siteId
     * @return ElementInterface|null
     */
    public static function getElementById(int $elementId, $siteId = null): ?ElementInterface
    {
        $elementType = self::getElementTypeById($elementId);
        if (!$elementType) {
            return null;
        } // relation is broken
        if (!$siteId) {
            $siteId = Craft::$app->sites->getPrimarySite()->id;
        }
        return $elementType::find()->id($elementId)->anyStatus()->siteId($siteId)->one();
    }

    /**
     * Get the element type via the element id.
     * @param int $elementId
     * @return mixed|null
     */
    private static function getElementTypeById(int $elementId): ?string
    {
        $elementType = (new Query())->select(['type'])
            ->from(Table::ELEMENTS)
            ->where(['id' => $elementId])
            ->one();
        if (!$elementType) {
            return null;
        }
        return $elementType['type'];
    }

    /**
     * Get root element of an element. Mainly used for Matrix, SuperTable, and Neo blocks.
     * @param ElementInterface $element
     * @param $siteId
     * @return ElementInterface|null
     */
    public static function getRootElement(ElementInterface $element, $siteId): ?ElementInterface
    {
        if (!isset($element->ownerId) || !$element->ownerId) {
            return $element;
        }
        $sourceElement = self::getElementById($element->ownerId, $siteId);
        if (!$sourceElement) {
            return null;
        }
        return self::getRootElement($sourceElement, $siteId);
    }


    public static function getRelationsUsedInElement(int $elementId): array
    {
        $elementIds = (new Query())
            ->select(['relations.targetId'])
            ->from(['relations' => Table::RELATIONS])
            ->where(['relations.sourceId' => $elementId])
            ->column();
        $result = collect($elementIds)->map(function ($elementId) {
            return (int)$elementId;
        });

        if (RedactorService::isRedactorEnabled()) {
            $redactorRelations = RedactorService::getRedactorRelationsUsedInElement($elementId);
            $result = $result->merge($redactorRelations);
        }

        return $result->all();
    }
}