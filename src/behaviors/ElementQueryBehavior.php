<?php

declare(strict_types=1);

namespace internetztube\elementRelations\behaviors;

use Craft;
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
                ->andWhere(['or', ['elementrelations_cache.sourceElementId' => null], ['and', ['not', ['sources.id' => null]], ['sources.dateDeleted' => null], ['sources.type' => Craft::$app->getElements()->getAllElementTypes()]]]),
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
        // a list spanning every site shows each element once, in the site the index asked for when it uses the
        // target there, else its first site that does (the Site column names all of them)
        $owner = $this->owner;
        if ($owner instanceof \internetztube\elementRelations\elements\db\UsageQuery && $owner->allSites) {
            $ids = implode(',', array_map(intval(...), $targetIds));
            $only = array_map(intval(...), array_filter((array)$owner->inSites, is_numeric(...)));
            $preferred = (int)($owner->preferredSiteId ?? 0);
            $subQuery->andWhere(new Expression(sprintf(
                '[[elements_sites.siteId]] = (SELECT [[c.sourceSiteId]] FROM %s [[c]] WHERE [[c.targetElementId]] IN (%s) AND [[c.sourcePrimaryOwnerId]] = [[elements.id]]%s ORDER BY CASE WHEN [[c.sourceSiteId]] = %d THEN 0 ELSE 1 END, [[c.sourceSiteId]] LIMIT 1)',
                Craft::$app->getDb()->quoteTableName('{{%elementrelations_cache}}'),
                $ids ?: '0',
                $only ? ' AND [[c.sourceSiteId]] IN (' . implode(',', $only) . ')' : '',
                $preferred,
            )));
        }
    }
}
