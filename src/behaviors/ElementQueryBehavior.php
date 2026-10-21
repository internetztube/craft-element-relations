<?php

namespace internetztube\elementRelations\behaviors;

use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\db\ElementQuery;
use yii\base\Behavior;
use yii\db\Expression;

/**
 * Adds the `usesElement` parameter to every element query: only elements that use (relate to) the given element(s),
 * according to the relations cache. Nested elements count for their top-level owner.
 *
 * ```twig
 * {% set pages = craft.entries().usesElement(asset).all() %}
 * ```
 * Also usable as element index criteria (`{usesElement: 123}`), which is how the field renders its native indexes.
 *
 * @property ElementQuery $owner
 */
class ElementQueryBehavior extends Behavior
{
    /** int|int[]|ElementInterface|ElementInterface[]|null */
    public mixed $usesElement = null;

    /**
     * @param int|int[]|ElementInterface|ElementInterface[]|null $value
     */
    public function usesElement(mixed $value): ElementQuery
    {
        $this->usesElement = $value;
        return $this->owner;
    }

    public function events(): array
    {
        return [ElementQuery::EVENT_AFTER_PREPARE => 'applyUsesElement'];
    }

    /** Runs after Craft created the sub query, which is rebuilt on every prepare (count, all, ...). */
    public function applyUsesElement(): void
    {
        if ($this->usesElement === null) {
            return;
        }

        $targetIds = [];
        foreach (is_array($this->usesElement) ? $this->usesElement : [$this->usesElement] as $target) {
            $targetIds[] = $target instanceof ElementInterface ? (int)$target->id : (int)$target;
        }

        $this->owner->subQuery->andWhere(['exists', (new Query())
            ->select(new Expression('1'))
            ->from(['elementrelations_cache' => '{{%elementrelations_cache}}'])
            ->where('[[elementrelations_cache.sourcePrimaryOwnerId]] = [[elements.id]]')
            ->andWhere('[[elementrelations_cache.sourceSiteId]] = [[elements_sites.siteId]]')
            ->andWhere(['elementrelations_cache.targetElementId' => $targetIds]),
        ]);
    }
}
