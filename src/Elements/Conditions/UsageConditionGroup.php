<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Elements\Conditions;

use CraftCms\Cms\Condition\Enums\GroupOperator;
use CraftCms\Cms\Element\Conditions\Contracts\ElementQueryConditionRuleInterface;
use CraftCms\Cms\Element\Conditions\ElementConditionGroup;
use CraftCms\Cms\Element\Queries\Contracts\ElementQueryInterface;
use CraftCms\Cms\Entry\Conditions\SectionConditionRule;
use CraftCms\Cms\Entry\Elements\Entry;
use Illuminate\Database\Query\Builder;
use Override;
use RuntimeException;

/**
 * The rules of a usage list's filter, as Craft applies them, except that Craft's Section rule gets an entry query of
 * its own: it wants one (to pick a structure for one section), and its SQL goes on the list's query like every rule's.
 */
class UsageConditionGroup extends ElementConditionGroup
{
    #[Override]
    public function modifyQuery(Builder $query, ElementQueryInterface $elementQuery): void
    {
        $method = $this->operator === GroupOperator::And ? 'where' : 'orWhere';
        foreach ($this->getRules() as $rule) {
            $query->$method(function (Builder $query) use ($elementQuery, $rule): void {
                try {
                    /** @var self|ElementQueryConditionRuleInterface $rule */
                    $rule->modifyQuery($query, $rule instanceof SectionConditionRule ? Entry::find() : $elementQuery);
                } catch (RuntimeException) {
                    // misconfigured, skipped as Craft does
                }
            });
        }
    }
}
