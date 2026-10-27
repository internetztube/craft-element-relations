<?php

declare(strict_types=1);

namespace internetztube\elementRelations\elements\conditions;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use internetztube\elementRelations\elements\RestrictedUsage;
use internetztube\elementRelations\elements\Usage;

/**
 * "Element type is / is not …" in the filter of the field's usage list: the types in the list (Usage::listedTypes()),
 * offered also when there is only one. The same `elementTypes` param as the toolbar's type dropdown.
 */
class ElementTypeConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('element-relations', 'filter-element-type');
    }

    /** @return list<string> */
    public function getExclusiveQueryParams(): array
    {
        return ['elementTypes'];
    }

    /** @return list<array{value: string, label: string}> */
    protected function options(): array
    {
        return array_map(fn(string $type): array => ['value' => $type, 'label' => $type::displayName()], $this->listedTypes());
    }

    /**
     * The list's types, from its filter (they survive the condition builder's own requests, see UsageCondition).
     * @return list<class-string<ElementInterface>>
     */
    private function listedTypes(): array
    {
        $condition = $this->getCondition();
        return $condition instanceof UsageCondition ? $condition->getListedTypes() : Usage::listedTypes();
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        $values = $this->getValues();
        $types = $this->operator === self::OPERATOR_NOT_IN ? array_values(array_diff($this->listedTypes(), $values)) : $values;
        // no type left: nothing (an empty param would not filter at all)
        Craft::configure($query, ['elementTypes' => $types ?: ['-']]);
    }

    public function matchElement(ElementInterface $element): bool
    {
        return $this->matchValue($element instanceof RestrictedUsage ? $element->realType : $element::class);
    }
}
