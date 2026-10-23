<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Elements\Conditions;

use CraftCms\Cms\Asset\Conditions as AssetRules;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Condition\Contracts\ConditionRuleInterface;
use CraftCms\Cms\Element\Conditions\Contracts\ElementQueryConditionRuleInterface;
use CraftCms\Cms\Element\Conditions\ElementCondition;
use CraftCms\Cms\Entry\Conditions as EntryRules;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\User\Conditions as UserRules;
use CraftCms\Cms\User\Elements\User;
use Internetztube\ElementRelations\Elements\Usage;
use Override;

/**
 * The filter of a usage list: Craft's generic rules (dates, id, related to, site, …) plus the stock rules of the
 * element types in the list, as Craft's own indexes offer them. Those rules write their conditions against their
 * type's table (`entries.sectionId`, `assets.filename`, …), which UsageQuery joins; rows of other types never match.
 */
class UsageCondition extends ElementCondition
{
    /** @var array<class-string, list<class-string<ConditionRuleInterface>>> */
    private const RULES = [
        Entry::class => [
            EntryRules\SectionConditionRule::class,
            EntryRules\TypeConditionRule::class,
            EntryRules\AuthorConditionRule::class,
            EntryRules\PostDateConditionRule::class,
            EntryRules\ExpiryDateConditionRule::class,
        ],
        Asset::class => [
            AssetRules\VolumeConditionRule::class,
            AssetRules\FilenameConditionRule::class,
            AssetRules\FileTypeConditionRule::class,
            AssetRules\FileSizeConditionRule::class,
            AssetRules\WidthConditionRule::class,
            AssetRules\HeightConditionRule::class,
            AssetRules\DateModifiedConditionRule::class,
        ],
        User::class => [
            UserRules\EmailConditionRule::class,
            UserRules\UsernameConditionRule::class,
            UserRules\FirstNameConditionRule::class,
            UserRules\LastNameConditionRule::class,
            UserRules\LastLoginDateConditionRule::class,
        ],
    ];

    /** @return list<string|array<string, mixed>> */
    #[Override]
    protected function selectableConditionRules(): array
    {
        $rules = parent::selectableConditionRules();
        foreach (Usage::listedTypes() as $type) {
            foreach (self::RULES[$type] ?? [] as $rule) {
                if (!in_array($rule, $rules, true)) {
                    $rules[] = $rule;
                }
            }
        }
        return $rules;
    }

    /** The stock rules are made for their type's own condition (EntryCondition, …); here they are offered as they are. */
    #[Override]
    protected function isConditionRuleSelectable(ConditionRuleInterface $rule): bool
    {
        foreach (self::RULES as $rules) {
            if (in_array($rule::class, $rules, true)) {
                return $rule::isSelectable() && (!$this->forQuery || $rule instanceof ElementQueryConditionRuleInterface);
            }
        }
        return parent::isConditionRuleSelectable($rule);
    }

    /** The list's own base rule is never offered ("Add a filter"), only taken from the list's config. */
    #[Override]
    protected function validateConditionRule(ConditionRuleInterface $rule): bool
    {
        return $rule instanceof UsesElementConditionRule || parent::validateConditionRule($rule);
    }
}
