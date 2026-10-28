<?php

declare(strict_types=1);

namespace internetztube\elementRelations\elements\conditions;

use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\conditions\ElementCondition;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use internetztube\elementRelations\elements\Usage;

/**
 * The filter of a usage list: Craft's generic rules (dates, id, related to, site, …) plus the stock rules of the
 * element types in the list, as Craft's own indexes offer them. The rules call Craft's query params (`sectionId`,
 * `filename`, `groupId`, …), which UsageQuery takes; a rule of one type never matches rows of another.
 */
class UsageCondition extends ElementCondition
{
    /** @var array<class-string, list<class-string>> */
    private const RULES = [
        Entry::class => [
            \craft\elements\conditions\entries\SectionConditionRule::class,
            \craft\elements\conditions\entries\TypeConditionRule::class,
            \craft\elements\conditions\entries\AuthorConditionRule::class,
            \craft\elements\conditions\entries\PostDateConditionRule::class,
            \craft\elements\conditions\entries\ExpiryDateConditionRule::class,
        ],
        Asset::class => [
            \craft\elements\conditions\assets\VolumeConditionRule::class,
            \craft\elements\conditions\assets\FilenameConditionRule::class,
            \craft\elements\conditions\assets\FileTypeConditionRule::class,
            \craft\elements\conditions\assets\FileSizeConditionRule::class,
            \craft\elements\conditions\assets\WidthConditionRule::class,
            \craft\elements\conditions\assets\HeightConditionRule::class,
            \craft\elements\conditions\assets\DateModifiedConditionRule::class,
        ],
        Category::class => [\craft\elements\conditions\categories\GroupConditionRule::class],
        Tag::class => [\craft\elements\conditions\tags\GroupConditionRule::class],
        User::class => [
            \craft\elements\conditions\users\EmailConditionRule::class,
            \craft\elements\conditions\users\UsernameConditionRule::class,
            \craft\elements\conditions\users\FirstNameConditionRule::class,
            \craft\elements\conditions\users\LastNameConditionRule::class,
            \craft\elements\conditions\users\LastLoginDateConditionRule::class,
        ],
    ];

    /**
     * The element types of the list (Usage::listedTypes() when the filter is first built). Kept in the condition's config,
     * which Craft's condition builder carries through its own requests (adding a rule re-renders the filter without the
     * list's parameters), so the rules offered and the Element Type options stay the list's.
     * @var list<class-string<\craft\base\ElementInterface>>|null
     */
    public ?array $listedTypes = null;

    public function init(): void
    {
        parent::init();
        $this->listedTypes ??= Usage::listedTypes(); // when built; the builder's requests configure it from the config
    }

    /**
     * Also while Craft builds the condition: its rules are set from the config before init() runs.
     * @return list<class-string<\craft\base\ElementInterface>>
     */
    public function getListedTypes(): array
    {
        return $this->listedTypes ?? Usage::listedTypes();
    }

    /** @return array<string, mixed> */
    protected function config(): array
    {
        return parent::config() + ['listedTypes' => $this->getListedTypes()];
    }

    /** Craft's group rules of categories and tags both set groupId; category and tag group ids overlap, so each goes to its own type. */
    public function modifyQuery(\craft\elements\db\ElementQueryInterface $query): void
    {
        foreach ($this->getConditionRules() as $rule) {
            try {
                /** @var \craft\elements\conditions\ElementConditionRuleInterface $rule */
                $rule->modifyQuery($query);
            } catch (\yii\base\InvalidConfigException) {
                continue; // misconfigured, as Craft skips it
            }
            $param = match (true) {
                $rule instanceof \craft\elements\conditions\categories\GroupConditionRule => 'categoryGroupId',
                $rule instanceof \craft\elements\conditions\tags\GroupConditionRule => 'tagGroupId',
                default => null,
            };
            if ($param !== null && $query instanceof \internetztube\elementRelations\elements\db\UsageQuery) {
                $query->$param = $query->groupId;
                $query->groupId = null;
            }
        }
    }

    protected function selectableConditionRules(): array
    {
        // the list's own base rule, and the element type (also when the list has only one)
        $rules = [UsesElementConditionRule::class, ElementTypeConditionRule::class, ...parent::selectableConditionRules()];
        foreach ($this->getListedTypes() as $type) {
            foreach (self::RULES[$type] ?? [] as $rule) {
                if (class_exists($rule) && !in_array($rule, $rules, true)) {
                    $rules[] = $rule;
                }
            }
        }
        return $rules;
    }
}
