<?php

declare(strict_types=1);

namespace internetztube\elementRelations\elements\conditions;

use Craft;
use craft\base\conditions\BaseConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;

/**
 * "Uses element X (in site S)", the base condition of the field's usage list (ElementRelationsField::inputHtml()). Craft sends an
 * index's base condition with every request of that index, the filter HUD's included, so the list's target is known
 * everywhere (Usage::listedTypes()). It takes the `usesElement` query param, which hides it from "Add a filter".
 */
class UsesElementConditionRule extends BaseConditionRule implements ElementConditionRuleInterface
{
    /** @var int|list<int>|null the element(s) whose usages the list shows */
    public int|array|null $elementId = null;

    /** The site of the element(s): the list shows the usages of the element in that site */
    public int|string|null $siteId = null;

    public function getLabel(): string
    {
        return Craft::t('element-relations', 'field-column-where');
    }

    /** @return list<string> */
    public function getExclusiveQueryParams(): array
    {
        return ['usesElement', 'usesElementSite', 'unused'];
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return ['elementId' => $this->elementId, 'siteId' => $this->siteId] + parent::getConfig();
    }

    /**
     * Craft sets a rule's config through setAttributes(): only safe attributes get through.
     * @return array<int, array<int|string, mixed>>
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [[['elementId', 'siteId'], 'safe']]);
    }

    public function getHtml(): string
    {
        return ''; // never shown: the list's target is fixed
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        Craft::configure($query, ['usesElement' => $this->elementId, 'usesElementSite' => $this->siteId]); // behavior params, see ElementQueryBehavior
    }

    public function matchElement(ElementInterface $element): bool
    {
        return (new \craft\db\Query())
            ->from('{{%elementrelations_cache}}')
            ->where(['targetElementId' => $this->elementId, 'sourcePrimaryOwnerId' => $element->id, 'sourceSiteId' => $element->siteId])
            ->andFilterWhere(['targetSiteId' => \internetztube\elementRelations\behaviors\ElementQueryBehavior::targetSiteIds($this->siteId)])
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
