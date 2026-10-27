<?php

declare(strict_types=1);

namespace internetztube\elementRelations\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\events\RegisterComponentTypesEvent;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;
use internetztube\elementRelations\services\extractors\InterfaceElementExtractor;
use internetztube\elementRelations\services\extractors\InterfaceFieldExtractor;
use yii\base\Event;

class ExtractorService
{
    /**
     * Lets plugins add (or remove) field extractors. Handlers modify `$event->types` (class names implementing
     * InterfaceFieldExtractor, ideally InterfaceFieldReplacer as well). Order matters: the first extractor that
     * returns records for a field wins, so add specific ones before the generic BaseRelation/RelationalField ones.
     *
     * ```php
     * Event::on(ExtractorService::class, ExtractorService::EVENT_REGISTER_FIELD_EXTRACTORS, function (RegisterComponentTypesEvent $e) {
     *     array_unshift($e->types, MyFieldExtractor::class);
     * });
     * ```
     */
    public const EVENT_REGISTER_FIELD_EXTRACTORS = 'registerFieldExtractors';

    /** Same for element extractors (InterfaceElementExtractor, ideally InterfaceElementReplacer as well). */
    public const EVENT_REGISTER_ELEMENT_EXTRACTORS = 'registerElementExtractors';

    /** @var list<class-string<InterfaceFieldExtractor>>|null */
    private static ?array $fieldExtractors = null;
    /** @var list<class-string<InterfaceElementExtractor>>|null */
    private static ?array $elementExtractors = null;

    /** @return list<class-string<InterfaceFieldExtractor>> the built-in field extractors plus registered ones (installed classes only) */
    public static function fieldExtractors(): array
    {
        return self::$fieldExtractors ??= self::registered(self::EVENT_REGISTER_FIELD_EXTRACTORS, self::FIELD_EXTRACTORS);
    }

    /** @return list<class-string<InterfaceElementExtractor>> */
    public static function elementExtractors(): array
    {
        return self::$elementExtractors ??= self::registered(self::EVENT_REGISTER_ELEMENT_EXTRACTORS, self::ELEMENT_EXTRACTORS);
    }

    /** Forgets the registered extractors (tests, or plugins registering late). */
    public static function reset(): void
    {
        self::$fieldExtractors = self::$elementExtractors = null;
    }

    /**
     * @template T of object
     * @param list<class-string<T>> $types
     * @return list<class-string<T>>
     */
    private static function registered(string $eventName, array $types): array
    {
        if (Event::hasHandlers(self::class, $eventName)) {
            $event = new RegisterComponentTypesEvent(['types' => $types]);
            Event::trigger(self::class, $eventName, $event);
            /** @var list<class-string<T>> $types handlers are asked to add implementations of the same interface */
            $types = $event->types;
        }
        return array_values(array_filter($types, 'class_exists'));
    }

    /**
     * Field extractors, tried in order; the first one that returns records for a field wins.
     * Each checks itself whether its plugin is installed, so listing one for a missing plugin is harmless.
     * @var list<class-string<InterfaceFieldExtractor>>
     */
    public const FIELD_EXTRACTORS = [
        // core relation fields (also picks up drafts of the targets)
        extractors\FieldExtractorBaseRelationService::class,
        // everything else Craft itself writes to the `relations` table (core Link field, plugin fields)
        extractors\FieldExtractorRelationalFieldService::class,
        // plugins that keep element references outside the `relations` table
        extractors\FieldExtractorHyperService::class,
        extractors\FieldExtractorSeomaticService::class,
        extractors\FieldExtractorLinkItService::class,
        extractors\FieldExtractorRedactorCkEditorService::class,
        extractors\FieldExtractorTypedLinkFieldService::class,
        extractors\FieldExtractorVizyService::class,
        extractors\FieldExtractorFreeLinkService::class,
        extractors\FieldExtractorEtherSeoService::class,
        extractors\FieldExtractorLinkMateService::class,
    ];

    /** The columns that identify a relation (everything but id/uid/dates). */
    private const ROW_COLUMNS = ['sourceElementId', 'sourceSiteId', 'sourcePrimaryOwnerId', 'targetElementId', 'targetSiteId', 'fieldId', 'customFieldUid', 'type'];

    /**
     * Element extractors: relations that belong to the element itself rather than to one of its fields
     * (a user's photo, a navigation node's linked element, ...). All of them run.
     * @var list<class-string<InterfaceElementExtractor>>
     */
    public const ELEMENT_EXTRACTORS = [
        extractors\ElementExtractorUserPhotoService::class,
        extractors\ElementExtractorNavigationNodeService::class,
        extractors\ElementExtractorWishlistItemService::class,
        extractors\ElementExtractorFormieFormService::class,
    ];

    /**
     * @throws \yii\db\Exception
     */
    public static function refreshRelationsForElement(ElementInterface $element): bool
    {
        self::$owners = self::$inBatch ? self::$owners : [];
        [$ownerId, $hidden] = self::primaryOwner($element);
        // revisions are never shown as usages, and neither are the nested elements (Matrix entries, Neo blocks) Craft
        // keeps for them, nor the Neo blocks a field converted to Matrix left behind, which reach this through other
        // paths than the save event (owner trees, full rebuilds)
        if ($element->getIsRevision() || $hidden) {
            \internetztube\elementRelations\models\RelationsModel::forgetPrefetched();
            return ElementRelationsCacheRecord::deleteAll(['sourceElementId' => $element->id, 'sourceSiteId' => $element->siteId]) > 0;
        }

        $fields = $element->getFieldLayout()?->getCustomFields() ?? [];

        $baseRecord = new ElementRelationsCacheRecord([
            'sourceElementId' => $element->id,
            'sourceSiteId' => $element->siteId,
            'sourcePrimaryOwnerId' => $ownerId,
        ]);

        $records = collect($fields)
            ->map(fn(FieldInterface $field): array => $field instanceof Field ? self::fieldRelations($field, $element, $baseRecord) : [])
            ->flatten()
            ->merge(collect(self::elementExtractors())
                ->map(fn(string $extractor): mixed => self::safely($extractor, $element, fn(): mixed => $extractor::getRelations($element, clone $baseRecord)))
                ->flatten())
            ->filter();

        // Write only when the relations actually changed: keeps row ids/dates stable and skips the delete+insert
        // churn for the common case (an element saved without touching its relations).
        $normalize = fn(array $rows): array => collect($rows)
            ->map(fn(array $row): string => implode('|', array_map(fn(string $column): string => $row[$column] === null ? '' : (string)$row[$column], self::ROW_COLUMNS)))
            ->sort()
            ->values()
            ->all();
        $existing = (new Query())
            ->select(self::ROW_COLUMNS)
            ->from(ElementRelationsCacheRecord::tableName())
            ->where(['sourceElementId' => $element->id, 'sourceSiteId' => $element->siteId])
            ->all();
        if ($normalize($existing) === $normalize($records->map(fn(ElementRelationsCacheRecord $record): array => $record->getAttributes(self::ROW_COLUMNS))->all())) {
            return false;
        }

        self::deleteRelationsForElement($element);
        // one INSERT for all of them (Craft adds dateCreated, dateUpdated and uid)
        if ($records->isNotEmpty()) {
            Craft::$app->getDb()->createCommand()->batchInsert(
                ElementRelationsCacheRecord::tableName(),
                self::ROW_COLUMNS,
                $records->map(fn(ElementRelationsCacheRecord $record): array => array_values($record->getAttributes(self::ROW_COLUMNS)))->all(),
            )->execute();
        }
        \internetztube\elementRelations\elements\db\UsageQuery::resetPermissionCache();
        \internetztube\elementRelations\models\RelationsModel::forgetPrefetched();
        return true;
    }

    /**
     * Ids of the elements this element references through one field (or, with `$field = null`, through the element-level
     * extractors), as the extractors read them right now (unsaved values included).
     * @return list<int>
     */
    public static function targetIds(ElementInterface $element, ?Field $field): array
    {
        $base = new ElementRelationsCacheRecord(['sourceElementId' => $element->id, 'sourceSiteId' => $element->siteId]);
        $records = $field
            ? self::fieldRelations($field, $element, $base)
            : collect(self::elementExtractors())
                ->map(fn(string $extractor): mixed => self::safely($extractor, $element, fn(): mixed => $extractor::getRelations($element, clone $base)))
                ->flatten()
                ->filter()
                ->all();
        return array_values(array_unique(array_map(fn(ElementRelationsCacheRecord $record): int => (int)$record->targetElementId, $records)));
    }

    /** Whether an element-level extractor (user photo, navigation node, ...) applies to this element. */
    public static function hasElementExtractor(ElementInterface $element): bool
    {
        foreach (self::elementExtractors() as $extractor) {
            foreach ($extractor::elementTypes() as $type) {
                if (is_a($element, $type)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Runs the field extractor chain for one field; the first extractor that returns records wins.
     * Public so extractors of container fields (e.g. Vizy blocks) can run it on their inner fields.
     * @return ElementRelationsCacheRecord[]
     */
    public static function fieldRelations(Field $field, ElementInterface $element, ElementRelationsCacheRecord $baseRecord): array
    {
        foreach (self::fieldExtractors() as $extractor) {
            $records = self::safely($extractor, $element, fn(): mixed => $extractor::getRelations($field, $element, clone $baseRecord));
            if (is_array($records) && $records) {
                return $records;
            }
        }
        return [];
    }

    /**
     * Drops the rows of an element that is gone (deleted, or a nested element removed from its owner), in every site
     * or in one (deleted for that site): its own rows and those of its nested elements, of which it is the owner.
     */
    public static function forgetElement(ElementInterface $element, ?int $siteId = null): void
    {
        \internetztube\elementRelations\elements\db\UsageQuery::resetPermissionCache();
        \internetztube\elementRelations\models\RelationsModel::forgetPrefetched();
        ElementRelationsCacheRecord::deleteAll(['and',
            ['or', ['sourceElementId' => $element->id], ['sourcePrimaryOwnerId' => $element->id]],
            $siteId === null ? [] : ['sourceSiteId' => $siteId],
        ]);
    }

    public static function deleteRelationsForElement(ElementInterface $element): void
    {
        \internetztube\elementRelations\elements\db\UsageQuery::resetPermissionCache();
        \internetztube\elementRelations\models\RelationsModel::forgetPrefetched();
        ElementRelationsCacheRecord::deleteAll([
            'sourceElementId' => $element->id,
            'sourceSiteId' => $element->siteId,
        ]);
    }

    /**
     * One misbehaving extractor (e.g. after a third-party plugin update) must not fail the whole refresh job,
     * which would leave every other element of the batch without relations. In dev mode the error is rethrown.
     * @param callable(): mixed $fn
     * @return mixed what `$fn` returns, false when it failed
     */
    private static function safely(string $extractor, ElementInterface $element, callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Craft::error(sprintf('%s failed for element #%s: %s', $extractor, $element->id, $e->getMessage()), 'element-relations');
            if (Craft::$app->getConfig()->getGeneral()->devMode) {
                throw $e;
            }
            return false;
        }
    }

    /**
     * The element an editor should see as "where it's used": nested elements (Matrix entries, Neo blocks, content
     * blocks, addresses, variants) resolve to their top-level owner; navigation nodes to their menu, wishlist items to
     * their list (a node or item is titled like the element it links to, so showing it would say nothing). With
     * whether nothing shows it: a revision, or a Neo block left behind (with what it owns).
     * @return array{int, bool}
     */
    private static function primaryOwner(ElementInterface $element): array
    {
        // converting a Neo field to Matrix keeps its blocks, owned as before, but no editor or page shows them any more
        // (Neo itself fails to load them: their field is no Neo field)
        if ($element instanceof \benf\neo\elements\Block && !(Craft::$app->getFields()->getFieldById((int)$element->fieldId) instanceof \benf\neo\Field)) {
            return [(int)$element->id, true];
        }
        if (property_exists($element, 'primaryOwnerId') && $element->primaryOwnerId) {
            // the blocks of one owner share it: load it once per batch (see inBatch())
            $key = $element->primaryOwnerId . ':' . $element->siteId;
            if (!array_key_exists($key, self::$owners)) {
                $owner = Craft::$app->getElements()->getElementById((int)$element->primaryOwnerId, null, $element->siteId);
                self::$owners[$key] = $owner ? self::primaryOwner($owner) : null;
            }
            if (self::$owners[$key] !== null) {
                return self::$owners[$key];
            }
        }
        // `instanceof` on a class of a plugin that isn't installed is simply false
        if ($element instanceof \verbb\navigation\elements\Node && class_exists(\verbb\navigation\elements\Menu::class) && $element->menuId) {
            return [(int)$element->menuId, false];
        }
        if ($element instanceof \verbb\wishlist\elements\Item && $element->listId) {
            return [(int)$element->listId, false];
        }
        return [(int)$element->id, $element->getIsRevision()];
    }

    /** @var array<string, array{int, bool}|null> primaryOwner() by `<primaryOwnerId>:<siteId>`; null: the owner is gone */
    private static array $owners = [];

    private static bool $inBatch = false;

    /**
     * Runs a batch of refreshes (ResaveElementRelationsJob) that loads each owner once; outside one every refresh looks
     * its owner up afresh (an owner can change between two saves of a request).
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function inBatch(callable $fn): mixed
    {
        [self::$inBatch, self::$owners] = [true, []];
        try {
            return $fn();
        } finally {
            [self::$inBatch, self::$owners] = [false, []];
        }
    }
}
