<?php

namespace internetztube\elementRelations\services;

use craft\base\Component;
use craft\base\Element;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\models\Site;
use yii\base\BaseObject;

class ElementRelationsService extends Component
{
    public static function getRelationsFromElement(Element $sourceElement, bool $anySite = false)
    {
        $elements = (new Query())->select(['elements.id'])
            ->from(['relations' => Table::RELATIONS])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[relations.sourceId]] = [[elements.id]]')
            ->where(['relations.targetId' => $sourceElement->canonicalId])
            ->column();

        $site = $anySite ? '*' : $sourceElement->site;

        return collect($elements)->map(function(int $elementId) use ($site) {
            $relation = self::getElementById($elementId, $site);
            if (!$relation) { return null; }
            return self::getRootElement($relation, $site);
        })->filter()->values()->toArray();
    }

    private static function getElementById (int $elementId, $site): ?Element
    {
        $result = (new Query())->select(['type'])->from(Table::ELEMENTS)->where(['id' => $elementId])->one();
        if (!$result) { return null; } // relation is broken
        return $result['type']::find()->id($elementId)->anyStatus()->site($site)->one();
    }

    private static function getRootElement (Element $element, $site): ?Element
    {
        if (!isset($element->ownerId) || !$element->ownerId) { return $element; }
        $sourceElement = self::getElementById($element->ownerId, $site);
        if (!$sourceElement) { return null; }
        return self::getRootElement($sourceElement, $site);
    }
}