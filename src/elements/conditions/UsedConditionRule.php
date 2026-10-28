<?php

declare(strict_types=1);

namespace internetztube\elementRelations\elements\conditions;

use Craft;
use craft\base\conditions\BaseSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use internetztube\elementRelations\behaviors\ElementQueryBehavior;

/**
 * Pro: the "Usage" rule for Craft's element conditions (index filter, custom sources, …): used live, used in any status,
 * not used live (only drafts, disabled, pending or expired elements use it), unused; in any site
 * (ElementQueryBehavior::$usage). Rules saved by earlier versions as the on/off "Used anywhere" keep their meaning.
 */
class UsedConditionRule extends BaseSelectConditionRule implements ElementConditionRuleInterface
{
    public string $value = ElementQueryBehavior::USAGE_LIVE;

    public function init(): void
    {
        parent::init();
        // "Used anywhere" on (saved as true, here "1") and off (false, here "")
        $this->value = match ($this->value) {
            '1' => ElementQueryBehavior::USAGE_ANY,
            '' => ElementQueryBehavior::USAGE_NONE,
            default => $this->value,
        };
    }

    public function getLabel(): string
    {
        return Craft::t('element-relations', 'condition-used');
    }

    /** @return list<string> */
    public function getExclusiveQueryParams(): array
    {
        return ['unused', 'usage', 'usesElement'];
    }

    /** @return list<array{value: string, label: string}> */
    protected function options(): array
    {
        return [
            ['value' => ElementQueryBehavior::USAGE_LIVE, 'label' => Craft::t('element-relations', 'usage-live')],
            ['value' => ElementQueryBehavior::USAGE_ANY, 'label' => Craft::t('element-relations', 'usage-any')],
            ['value' => ElementQueryBehavior::USAGE_NOT_LIVE, 'label' => Craft::t('element-relations', 'usage-not-live')],
            ['value' => ElementQueryBehavior::USAGE_NONE, 'label' => Craft::t('element-relations', 'usage-none')],
        ];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        Craft::configure($query, ['usage' => $this->value]);
    }

    public function matchElement(ElementInterface $element): bool
    {
        $query = $element::find()->id($element->id)->siteId($element->siteId)->status(null);
        Craft::configure($query, ['usage' => $this->value]);
        return $query->exists();
    }
}
