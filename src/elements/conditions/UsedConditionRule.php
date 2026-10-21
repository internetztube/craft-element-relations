<?php

namespace internetztube\elementRelations\elements\conditions;

use Craft;
use craft\base\conditions\BaseLightswitchConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use internetztube\elementRelations\models\RelationsModel;

/**
 * Pro: "Used anywhere" rule for Craft's element conditions (index filter HUD, custom sources, …).
 * Off = nothing relates to the element, see ElementQueryBehavior::$unused.
 */
class UsedConditionRule extends BaseLightswitchConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('element-relations', 'condition-used');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['unused', 'usesElement'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        Craft::configure($query, ['unused' => !$this->value]);
    }

    public function matchElement(ElementInterface $element): bool
    {
        return $this->matchValue((new RelationsModel($element->id, $element->siteId))->getIsInUseFast([]));
    }
}
