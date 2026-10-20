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
     * @var array<string, array<int, array{fieldId: int|null, type: string, disabledIn: int|null}>>
     */
    public static array $via = [];

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

    /** @return array<string, array{label: string, icon?: string}> */
    protected static function defineTableAttributes(): array
    {
        $attributes = [
            self::ATTR_TYPE => ['label' => Craft::t('element-relations', 'field-column-type')],
            self::ATTR_WHERE => ['label' => Craft::t('element-relations', 'field-column-where')],
            self::ATTR_FIELD => ['label' => Craft::t('element-relations', 'field-column-field')],
            self::ATTR_SITE => ['label' => Craft::t('app', 'Site')],
            // Craft's own attributes; a type without one (e.g. Authors on an asset) renders an empty cell, see ElementRelations::init()
            'status' => ['label' => Craft::t('app', 'Status')],
            'authors' => ['label' => Craft::t('app', 'Authors')],
            'postDate' => ['label' => Craft::t('app', 'Post Date')],
            'expiryDate' => ['label' => Craft::t('app', 'Expiry Date')],
            'link' => ['label' => Craft::t('app', 'Link'), 'icon' => 'world'],
            'slug' => ['label' => Craft::t('app', 'Slug')],
            'uri' => ['label' => Craft::t('app', 'URI')],
            'revisionCreator' => ['label' => Craft::t('app', 'Last Edited By')],
            'dateUpdated' => ['label' => Craft::t('app', 'Date Updated')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
            'id' => ['label' => Craft::t('app', 'ID')],
            'uid' => ['label' => Craft::t('app', 'UID')],
        ];
        if (Craft::$app->edition === CmsEdition::Solo) { // like Entry
            unset($attributes['authors'], $attributes['revisionCreator']);
        }
        return $attributes;
    }

    /** @return list<string> */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        $attributes = [self::ATTR_TYPE, self::ATTR_WHERE, self::ATTR_FIELD];
        if (Craft::$app->getIsMultiSite()) {
            $attributes[] = self::ATTR_SITE;
        }
        $attributes[] = 'dateUpdated';
        return $attributes;
    }

    /** @return list<array{label: string, orderBy: string, attribute: string, defaultDir?: string}> */
    protected static function defineSortOptions(): array
    {
        return [
            ['label' => Craft::t('app', 'Title'), 'orderBy' => 'title', 'attribute' => 'title'],
            ['label' => Craft::t('element-relations', 'field-column-type'), 'orderBy' => 'elements.type', 'attribute' => self::ATTR_TYPE],
            ['label' => Craft::t('app', 'Site'), 'orderBy' => 'elements_sites.siteId', 'attribute' => self::ATTR_SITE],
            ['label' => Craft::t('app', 'Date Updated'), 'orderBy' => 'elements.dateUpdated', 'attribute' => 'dateUpdated', 'defaultDir' => 'desc'],
            ['label' => Craft::t('app', 'Date Created'), 'orderBy' => 'elements.dateCreated', 'attribute' => 'dateCreated', 'defaultDir' => 'desc'],
            ['label' => Craft::t('app', 'ID'), 'orderBy' => 'elements.id', 'attribute' => 'id'],
        ];
    }
}
