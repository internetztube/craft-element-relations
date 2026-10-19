<?php

namespace internetztube\elementRelations\elements\db;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\helpers\Db;
use DateTime;
use internetztube\elementRelations\elements\Usage;

/**
 * Element query over all element types at once. Combine with the `usesElement` param:
 *
 * ```php
 * Usage::find()->usesElement($asset)->search('contact')->all(); // Entry, Product, Menu, ... instances
 * ```
 *
 * Filtering, searching, sorting, counting and paging run on the `elements` table across all types. The matched rows
 * are then loaded through their own element type's query (one query per type and site on the page), so every result
 * is a fully populated element of its real class.
 */
class UsageQuery extends ElementQuery
{
    /**
     * Restrict to these element type classes (the index's type dropdown sends this as criteria).
     * @var class-string<ElementInterface>|class-string<ElementInterface>[]|null
     */
    public mixed $elementTypes = null;

    public function __construct(array $config = [])
    {
        parent::__construct(Usage::class, $config);
    }

    /**
     * @param class-string<ElementInterface>|class-string<ElementInterface>[]|null $value
     */
    public function elementTypes(mixed $value): static
    {
        $this->elementTypes = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        if ($this->elementTypes) {
            $this->subQuery->andWhere(['elements.type' => (array)$this->elementTypes]);
        }
        // Date-based statuses (live / pending / expired) live in the entries and products tables. Left joins, so other
        // element types stay in the result (a plain joinElementTable() would inner-join and also drop Craft's type filter).
        $this->subQuery->leftJoin(['entries' => Table::ENTRIES], '[[entries.id]] = [[elements.id]]');
        if (self::hasProducts()) {
            $this->subQuery->leftJoin(['commerce_products' => '{{%commerce_products}}'], '[[commerce_products.id]] = [[elements.id]]');
        }
        return parent::beforePrepare();
    }

    /**
     * Entry statuses, applied to every element type: an entry or product is live/pending/expired by its dates, every
     * other enabled element counts as live.
     */
    protected function statusCondition(string $status): mixed
    {
        if (!in_array($status, [Entry::STATUS_LIVE, Entry::STATUS_PENDING, Entry::STATUS_EXPIRED], true)) {
            return parent::statusCondition($status);
        }

        $now = new DateTime();
        $now->setTime((int)$now->format('H'), (int)$now->format('i'), 59); // like EntryQuery: cacheable per minute
        $nowDb = Db::prepareDateForDb($now);
        $enabled = ['elements.enabled' => true, 'elements_sites.enabled' => true];
        $dated = self::hasProducts() ? ['entries', 'commerce_products'] : ['entries'];

        $perTable = fn(callable $condition) => array_map($condition, $dated);
        return match ($status) {
            Entry::STATUS_LIVE => [
                'and',
                $enabled,
                ...$perTable(fn(string $t) => [
                    'or',
                    ["$t.id" => null], // not an element of this dated type
                    ['and', ['<=', "$t.postDate", $nowDb], ['or', ["$t.expiryDate" => null], ['>', "$t.expiryDate", $nowDb]]],
                ]),
            ],
            Entry::STATUS_PENDING => ['and', $enabled, ['or', ...$perTable(fn(string $t) => ['>', "$t.postDate", $nowDb])]],
            Entry::STATUS_EXPIRED => ['and', $enabled, ['or', ...$perTable(fn(string $t) => ['<=', "$t.expiryDate", $nowDb])]],
        };
    }

    private static function hasProducts(): bool
    {
        return Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    /**
     * @param array $rows
     * @return ElementInterface[]|array[]
     */
    public function populate($rows): array
    {
        if ($this->asArray) {
            return parent::populate($rows);
        }

        // the element type of each row (not selected by the base query, and adding it to its select would break
        // Craft's search param, which reuses the query as an `id IN (...)` subquery)
        $types = (new Query())
            ->select(['id', 'type'])
            ->from(Table::ELEMENTS)
            ->where(['id' => array_column($rows, 'id')])
            ->pairs();

        $idsByTypeAndSite = [];
        foreach ($rows as $row) {
            $idsByTypeAndSite[$types[$row['id']] ?? ''][$row['siteId']][] = (int)$row['id'];
        }

        $elements = [];
        foreach ($idsByTypeAndSite as $type => $sites) {
            if (!is_subclass_of($type, ElementInterface::class)) {
                continue;
            }
            foreach ($sites as $siteId => $ids) {
                $loaded = $type::find()
                    ->id($ids)
                    ->siteId($siteId)
                    ->status(null)
                    ->drafts(null)
                    ->provisionalDrafts(null)
                    ->revisions(null)
                    ->trashed(null)
                    ->all();
                foreach ($loaded as $element) {
                    $elements["$type:$siteId:$element->id"] = $element;
                    Usage::$via["$element->id:$siteId"] ??= [];
                }
            }
        }

        // through which field(s) each loaded element uses the target (for the index's "Field" column)
        $targetIds = $this->getBehavior('elementRelations')?->usesElement ?? null;
        if ($targetIds !== null && $elements) {
            $targetIds = array_map(fn($t) => $t instanceof ElementInterface ? $t->id : (int)$t, is_array($targetIds) ? $targetIds : [$targetIds]);
            $rowsVia = (new Query())
                ->select(['sourcePrimaryOwnerId', 'sourceSiteId', 'fieldId', 'type'])
                ->distinct()
                ->from('{{%elementrelations_cache}}')
                ->where(['targetElementId' => $targetIds, 'sourcePrimaryOwnerId' => array_column($rows, 'id')])
                ->all();
            foreach ($rowsVia as $via) {
                Usage::$via["{$via['sourcePrimaryOwnerId']}:{$via['sourceSiteId']}"][] = ['fieldId' => $via['fieldId'] ? (int)$via['fieldId'] : null, 'type' => $via['type']];
            }
        }

        // keep the order of this query (sorting, pagination)
        $ordered = [];
        foreach ($rows as $row) {
            $key = ($types[$row['id']] ?? '') . ":{$row['siteId']}:{$row['id']}";
            if (isset($elements[$key])) {
                $ordered[] = $elements[$key];
            }
        }
        return $ordered;
    }
}
