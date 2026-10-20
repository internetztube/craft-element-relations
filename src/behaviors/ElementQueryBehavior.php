<?php

declare(strict_types=1);

namespace internetztube\elementRelations\behaviors;

use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
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
 * `unused()` is the inverse for a single element type: `craft.assets().unused()` lists assets nothing relates to.
 *
 * @property ElementQuery<array-key, ElementInterface> $owner
 */
class ElementQueryBehavior extends Behavior
{
    /** @var int|string|ElementInterface|array<int|string|ElementInterface>|null ids arrive as strings from element index criteria */
    public mixed $usesElement = null;

    /** Only elements nothing relates to: no cache row, which includes non-element usages such as SEOmatic settings. */
    public ?bool $unused = null;

    /**
     * @param int|string|ElementInterface|array<int|string|ElementInterface>|null $value
     * @return ElementQuery<array-key, ElementInterface>
     */
    public function usesElement(mixed $value): ElementQuery
    {
        $this->usesElement = $value;
        return $this->owner;
    }

    /** @return ElementQuery<array-key, ElementInterface> */
    public function unused(?bool $value = true): ElementQuery
    {
        $this->unused = $value;
        return $this->owner;
    }

    /** @return array<string, string> */
    public function events(): array
    {
        return [ElementQuery::EVENT_AFTER_PREPARE => 'applyParams'];
    }

    /** Runs after Craft created the sub query, which is rebuilt on every prepare (count, all, ...). */
    public function applyParams(): void
    {
        /** @var Query<array-key, mixed> $subQuery created by prepare() before this event */
        $subQuery = $this->owner->subQuery;
        if ($this->usesElement !== null || $this->unused) {
            // Craft caches element index queries for an hour, invalidated by the element type's cache tags. These
            // results also change when *other* elements are saved (a relation added somewhere, a source renamed or
            // disabled), which those tags never cover, so never serve them from the query cache.
            $this->owner->queryCacheDuration = null;
            $this->owner->queryCacheDependency = null;
        }
        if ($this->unused) {
            $subQuery->andWhere(['not exists', (new Query())
                ->select(['elementrelations_cache.id'])
                ->from(['elementrelations_cache' => '{{%elementrelations_cache}}'])
                // usages by trashed elements don't count, like everywhere else (they come back when restored);
                // plugin settings (no source element) do
                ->leftJoin(['sources' => Table::ELEMENTS], '[[sources.id]] = [[elementrelations_cache.sourcePrimaryOwnerId]]')
                ->where(['elementrelations_cache.targetElementId' => new Expression('[[elements.id]]')])
                ->andWhere(['or', ['elementrelations_cache.sourceElementId' => null], ['and', ['not', ['sources.id' => null]], ['sources.dateDeleted' => null]]]),
            ]);
        }
        if ($this->usesElement === null) {
            return;
        }

        $targetIds = [];
        foreach (is_array($this->usesElement) ? $this->usesElement : [$this->usesElement] as $target) {
            $targetIds[] = $target instanceof ElementInterface ? (int)$target->id : (int)$target;
        }

        $subQuery->andWhere(['exists', (new Query())
            ->select(['elementrelations_cache.id'])
            ->from(['elementrelations_cache' => '{{%elementrelations_cache}}'])
            ->where([
                'elementrelations_cache.sourcePrimaryOwnerId' => new Expression('[[elements.id]]'),
                'elementrelations_cache.sourceSiteId' => new Expression('[[elements_sites.siteId]]'),
                'elementrelations_cache.targetElementId' => $targetIds,
            ]),
        ]);
    }
}
