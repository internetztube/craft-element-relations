<?php

declare(strict_types=1);

namespace internetztube\elementRelations\elements;

use Craft;
use craft\base\Element;
use craft\elements\Entry;
use craft\enums\CmsEdition;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\elements\actions\ReplaceUsages;
use internetztube\elementRelations\elements\db\UsageQuery;

/**
 * Virtual element type for "everything that uses element X", regardless of element type.
 *
 * It is abstract on purpose: Craft only restricts a query to `elements.type = <class>` for concrete element classes
 * (see ElementQuery::prepare()), so a UsageQuery spans every element type, and UsageQuery::createElement() returns
 * each row as its real class (Entry, Asset, Product, ...). Craft's element index therefore works unchanged on top of
 * it: search, sorting, status/drafts menu, site menu, pagination, table and card views, chips with edit links.
 *
 * Never instantiated and not registered as an element type (so Craft's search index rebuild, GC etc. ignore it).
 */
abstract class Usage extends Element
{
    /** Table attributes rendered by the plugin via Element::EVENT_DEFINE_ATTRIBUTE_HTML (see ElementRelations::init()) */
    public const ATTR_TYPE = 'elementRelationsType';
    public const ATTR_WHERE = 'elementRelationsWhere';
    public const ATTR_SITE = 'elementRelationsSite';
    public const ATTR_FIELD = 'elementRelationsField';

    /**
     * Filled by UsageQuery::populate() for every element it loads: "<elementId>:<siteId>" => list of
     * ['fieldId' => int|null, 'type' => string, 'disabledIn' => int|null] describing through which field (or feature)
     * that element uses the queried target (empty without a `usesElement` param); `disabledIn` is the disabled nested
     * element (e.g. a Matrix block) the relation is in. Also tells the attribute HTML hook which elements are
     * being rendered in a Usage index.
     * Keyed by element id: one row per element, the fields of every site it uses the target in.
     * @var array<int, array<int, array{fieldId: int|null, type: string, disabledIn: int|null}>>
     */
    public static array $via = [];

    /**
     * Filled with $via: the sites each loaded element uses the queried target in, `<elementId>` => `<siteId>` => true.
     * A list spanning every site shows an element once; the Site column names these.
     * @var array<int, array<int, true>>
     */
    public static array $sites = [];

    public static function displayName(): string
    {
        return Craft::t('app', 'Element');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('app', 'element');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('app', 'Elements');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('app', 'elements');
    }

    public static function find(): UsageQuery
    {
        return new UsageQuery();
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    /** Enables the "Drafts" option in the index's status menu. */
    public static function hasDrafts(): bool
    {
        return true;
    }

    public static function isLocalized(): bool
    {
        return true;
    }

    /**
     * Entry statuses for the index's status menu; UsageQuery applies them to every element type.
     * @return array<string, string|array{label: string, color: \craft\enums\Color}>
     */
    public static function statuses(): array
    {
        return Entry::statuses();
    }

    /**
     * Exporting a mixed-type list makes no sense; this hides the index's Export button.
     * @return array{}
     */
    public static function exporters(string $source): array
    {
        return [];
    }

    /**
     * Pro: "Replace…" for the ticked usages; with an action, Craft's index shows its checkboxes and action bar. Nothing
     * else: Craft's defaults (Edit, Duplicate, Set status, Delete) would act on the relating elements themselves.
     * @return list<class-string<ReplaceUsages>>
     */
    public static function actions(string $source): array
    {
        return ElementRelations::canReplace() ? [ReplaceUsages::class] : [];
    }

    /** @return list<array{key: string, label: string}> */
    protected static function defineSources(string $context): array
    {
        return [['key' => '*', 'label' => Craft::t('app', 'All elements')]];
    }

    /**
     * Element types of the list being rendered, set by whoever renders one (the field, the delete dialog) before
     * Craft reads the table attributes; null = work them out from the request (see listedTypes()).
     * @var list<class-string<\craft\base\ElementInterface>>|null
     */
    public static ?array $listedTypes = null;

    /**
     * The plugin's columns, then every column of the element types the list contains (Section and Entry Type when
     * entries use the element, Filename and File Size for assets, Group, Email, …), like Craft's own indexes offer
     * them. A row whose type lacks a column (Section on a product) renders an empty cell, see ElementRelations::init().
     * @return array<string, array{label: string, icon?: string}>
     */
    protected static function defineTableAttributes(): array
    {
        $attributes = [
            self::ATTR_TYPE => ['label' => Craft::t('element-relations', 'field-column-type')],
            self::ATTR_WHERE => ['label' => Craft::t('element-relations', 'field-column-where')],
            self::ATTR_FIELD => ['label' => Craft::t('element-relations', 'field-column-field')],
            self::ATTR_SITE => ['label' => Craft::t('app', 'Site')],
            'status' => ['label' => Craft::t('app', 'Status')],
            'dateUpdated' => ['label' => Craft::t('app', 'Date Updated')],
        ];
        foreach (self::listedTypes() as $type) {
            foreach ($type::tableAttributes() as $key => $attribute) {
                // the first type's label wins (Entry Type, then Product Type both as `type`): each row renders its own
                if (is_string($key) && is_array($attribute) && isset($attribute['label']) && !isset($attributes[$key])) {
                    $attributes[$key] = ['label' => (string)$attribute['label']] + (isset($attribute['icon']) ? ['icon' => (string)$attribute['icon']] : []);
                }
            }
        }
        if (Craft::$app->edition === CmsEdition::Solo) { // like Entry
            unset($attributes['authors'], $attributes['revisionCreator']);
        }
        return $attributes;
    }

    /**
     * Element types of the list: as set by its renderer ($listedTypes), else those using the element(s) of the request's
     * `usesElement` criteria (the index's AJAX requests), else every type a list can contain.
     * @return list<class-string<\craft\base\ElementInterface>>
     */
    public static function listedTypes(): array
    {
        if (self::$listedTypes !== null) {
            return self::$listedTypes;
        }
        $request = Craft::$app->getRequest();
        if ($request instanceof \craft\web\Request) {
            // the list's base condition (every request of the index, the filter HUD's too), or its criteria
            $ids = \internetztube\elementRelations\elements\conditions\UsesElementConditionRule::idsIn($request->getBodyParam('condition') ?? $request->getQueryParam('condition'));
            $criteria = $request->getBodyParam('criteria') ?? $request->getQueryParam('criteria');
            $targets = is_array($criteria) ? (array)($criteria['usesElement'] ?? []) : [];
            $ids = $ids ?: array_values(array_filter(array_map(fn(mixed $id): int => is_numeric($id) ? (int)$id : 0, $targets)));
            if ($ids) {
                return self::typesUsing($ids);
            }
        }
        $types = [Entry::class, \craft\elements\Asset::class, \craft\elements\Category::class, \craft\elements\Tag::class, \craft\elements\User::class, \craft\elements\GlobalSet::class, \craft\elements\Address::class, 'craft\\commerce\\elements\\Product'];
        foreach (\internetztube\elementRelations\services\ExtractorService::elementExtractors() as $extractor) {
            array_push($types, ...$extractor::elementTypes());
        }
        return array_values(array_filter(array_unique($types), fn(string $type): bool => is_subclass_of($type, \craft\base\ElementInterface::class)));
    }

    /**
     * The element types using any of these elements (one grouped count query each, all sites).
     * @param list<int> $ids
     * @return list<class-string<\craft\base\ElementInterface>>
     */
    public static function typesUsing(array $ids): array
    {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $types = [];
        foreach ($ids as $id) {
            array_push($types, ...(new \internetztube\elementRelations\models\RelationsModel($id, $siteId))->getElementTypes());
        }
        return array_values(array_unique($types));
    }

    /** @return list<string> */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        // no Site column by default: the field lists the element's usages in its own site, which the relating
        // elements are in too, unless a relation crosses sites (the View menu offers the column)
        return [self::ATTR_TYPE, self::ATTR_WHERE, self::ATTR_FIELD, 'dateUpdated'];
    }

    /** Columns of the joined tables a stock sort option may name unqualified (unambiguous across them). */
    private const SORTABLE_COLUMNS = ['title', 'slug', 'uri', 'filename', 'size', 'kind', 'dateModified', 'width', 'height', 'username', 'fullName', 'firstName', 'lastName', 'email', 'lastLoginDate'];

    /** Element types whose stock sort options and filter rules the list takes over (their tables are joined, see UsageQuery). */
    public const STOCK_TYPES = [Entry::class, \craft\elements\Asset::class, \craft\elements\Category::class, \craft\elements\Tag::class, \craft\elements\User::class];

    /**
     * The plugin's sort options, then Craft's own of the element types in the list (Section, Entry Type, Post Date,
     * Filename, File Size, Group, Email, …), as they are: UsageQuery joins their tables, so their expressions resolve.
     * @return list<array<string, mixed>>
     */
    protected static function defineSortOptions(): array
    {
        $options = [
            ['label' => Craft::t('app', 'Title'), 'orderBy' => 'title', 'attribute' => 'title'],
            ['label' => Craft::t('element-relations', 'field-column-type'), 'orderBy' => 'elements.type', 'attribute' => self::ATTR_TYPE],
            ['label' => Craft::t('app', 'Site'), 'orderBy' => 'elements_sites.siteId', 'attribute' => self::ATTR_SITE],
            ['label' => Craft::t('app', 'Date Updated'), 'orderBy' => 'elements.dateUpdated', 'attribute' => 'dateUpdated', 'defaultDir' => 'desc'],
            ['label' => Craft::t('app', 'Date Created'), 'orderBy' => 'elements.dateCreated', 'attribute' => 'dateCreated', 'defaultDir' => 'desc'],
            ['label' => Craft::t('app', 'ID'), 'orderBy' => 'elements.id', 'attribute' => 'id'],
            ['label' => Craft::t('app', 'UID'), 'orderBy' => 'elements.uid', 'attribute' => 'uid'],
            // qualified: Commerce products have these columns too
            ['label' => Craft::t('app', 'Post Date'), 'orderBy' => 'entries.postDate', 'attribute' => 'postDate', 'defaultDir' => 'desc'],
            ['label' => Craft::t('app', 'Expiry Date'), 'orderBy' => 'entries.expiryDate', 'attribute' => 'expiryDate', 'defaultDir' => 'desc'],
        ];
        $seen = array_fill_keys(array_column($options, 'attribute'), true);
        foreach (array_intersect(self::listedTypes(), self::STOCK_TYPES) as $type) {
            foreach ($type::sortOptions() as $key => $option) {
                // Craft's shorthand: 'slug' => 'Slug'
                $option = is_string($option) ? ['label' => $option, 'orderBy' => $key, 'attribute' => $key] : $option;
                // a closure (Section, Entry Type, Group: Craft's fixed-order expressions on the joined tables) or a column
                // that resolves on them; `is_callable` would take 'link' for PHP's link()
                $orderBy = $option['orderBy'] ?? null;
                if (!is_array($option) || !is_string($option['label'] ?? null) || !($orderBy instanceof \Closure || (is_string($orderBy) && in_array($orderBy, self::SORTABLE_COLUMNS, true)))) {
                    continue;
                }
                $attribute = $option['attribute'] ?? (is_string($option['orderBy']) ? $option['orderBy'] : null);
                if (!is_string($attribute) || isset($seen[$attribute])) {
                    continue;
                }
                $seen[$attribute] = true;
                $options[] = $option + ['attribute' => $attribute];
            }
        }
        return $options;
    }

    /** The stock filter rules of the element types in the list, on top of Craft's generic ones. */
    public static function createCondition(): \craft\elements\conditions\ElementConditionInterface
    {
        return Craft::createObject(\internetztube\elementRelations\elements\conditions\UsageCondition::class, [static::class]);
    }
}
