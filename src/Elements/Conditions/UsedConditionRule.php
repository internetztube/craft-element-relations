<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Elements\Conditions;

use CraftCms\Cms\Condition\BaseLightswitchConditionRule;
use CraftCms\Cms\Element\Conditions\Contracts\ElementConditionRuleInterface;
use CraftCms\Cms\Element\Conditions\Contracts\ElementQueryConditionRuleInterface;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Queries\Contracts\ElementQueryInterface;
use Illuminate\Database\Query\Builder;
use Internetztube\ElementRelations\Elements\Queries\UsesElement;
use Internetztube\ElementRelations\Models\RelationsModel;

use function CraftCms\Cms\t;

/**
 * "Used anywhere" rule for Craft's element conditions (index filter HUD, custom sources, …).
 * On = something relates to the element, in any site; off = nothing does (UsesElement's `unused`).
 */
class UsedConditionRule extends BaseLightswitchConditionRule implements ElementConditionRuleInterface, ElementQueryConditionRuleInterface
{
    public function getLabel(): string
    {
        return t('condition-used', category: 'element-relations');
    }

    public function modifyQuery(Builder $query, ElementQueryInterface $elementQuery): void
    {
        UsesElement::applyUnused($query, !$this->value);
    }

    public function matchElement(ElementInterface $element): bool
    {
        return $this->matchValue((new RelationsModel((int)$element->id, (int)$element->siteId))->getIsInUseFast([]));
    }
}
