<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Elements\Conditions;

use CraftCms\Cms\Condition\BaseConditionRule;
use CraftCms\Cms\Element\Conditions\Contracts\ElementConditionRuleInterface;
use CraftCms\Cms\Element\Conditions\Contracts\ElementQueryConditionRuleInterface;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Queries\Contracts\ElementQueryInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Internetztube\ElementRelations\Elements\Queries\UsageQuery;
use Internetztube\ElementRelations\Elements\Queries\UsesElement;
use Internetztube\ElementRelations\Models\CacheRecord;
use Override;

use function CraftCms\Cms\t;

/**
 * "Uses element X (in site S)", the base condition of the field's usage list (ElementRelationsField). Craft sends an
 * index's base condition with every request of that index, the filter HUD's included, so the list's target is known
 * everywhere (Usage::listedTypes()). UsageCondition takes it from the list's config but never offers it.
 */
class UsesElementConditionRule extends BaseConditionRule implements ElementConditionRuleInterface, ElementQueryConditionRuleInterface
{
    /** @var int|list<int>|null the element(s) whose usages the list shows */
    public int|array|null $elementId = null;

    /** The site of the element(s): the list shows the usages of the element in that site */
    public int|string|null $siteId = null;

    public function getLabel(): string
    {
        return t('field-column-where', category: 'element-relations');
    }

    /** @return array<string, mixed> */
    #[Override]
    public function getConfig(): array
    {
        return ['elementId' => $this->elementId, 'siteId' => $this->siteId] + parent::getConfig();
    }

    /** @return array<string, mixed> */
    #[Override]
    public function getRules(): array
    {
        return array_merge(parent::getRules(), ['elementId' => ['nullable'], 'siteId' => ['nullable']]);
    }

    public function modifyQuery(Builder $query, ElementQueryInterface $elementQuery): void
    {
        // the field's list also gives the target as criteria, which UsageQuery applies already
        if ($this->elementId === null || ($elementQuery instanceof UsageQuery && $elementQuery->usesElement !== null)) {
            return;
        }
        UsesElement::applyUsesElement($query, $elementQuery, $this->elementId, $this->siteId);
    }

    public function matchElement(ElementInterface $element): bool
    {
        $siteIds = UsesElement::targetSiteIds($this->siteId);
        return DB::table(CacheRecord::TABLE)
            ->whereIn('targetElementId', (array)$this->elementId)
            ->where('sourcePrimaryOwnerId', $element->id)
            ->where('sourceSiteId', $element->siteId)
            ->when($siteIds !== null, fn(Builder $query) => $query->whereIn('targetSiteId', (array)$siteIds))
            ->exists();
    }

    /** @return list<int> the element ids of such a rule in a request's `condition` config, if any */
    public static function idsIn(mixed $conditionConfig): array
    {
        if (is_string($conditionConfig)) {
            $conditionConfig = json_decode($conditionConfig, true);
        }
        $ids = [];
        foreach (is_array($conditionConfig) ? (array)($conditionConfig['conditionRules'] ?? []) : [] as $rule) {
            if (is_array($rule) && ($rule['class'] ?? null) === self::class) {
                foreach ((array)($rule['elementId'] ?? []) as $id) {
                    if (is_numeric($id)) {
                        $ids[] = (int)$id;
                    }
                }
            }
        }
        return $ids;
    }
}
