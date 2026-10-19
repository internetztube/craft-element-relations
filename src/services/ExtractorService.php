<?php

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

    private static ?array $fieldExtractors = null;
    private static ?array $elementExtractors = null;

    /** @return class-string<InterfaceFieldExtractor>[] the built-in field extractors plus registered ones (installed classes only) */
    public static function fieldExtractors(): array
    {
        return self::$fieldExtractors ??= self::registered(self::EVENT_REGISTER_FIELD_EXTRACTORS, self::FIELD_EXTRACTORS);
    }

    /** @return class-string<InterfaceElementExtractor>[] */
    public static function elementExtractors(): array
    {
        return self::$elementExtractors ??= self::registered(self::EVENT_REGISTER_ELEMENT_EXTRACTORS, self::ELEMENT_EXTRACTORS);
    }

    /** Forgets the registered extractors (tests, or plugins registering late). */
    public static function reset(): void
    {
        self::$fieldExtractors = self::$elementExtractors = null;
    }

    private static function registered(string $eventName, array $types): array
    {
        if (Event::hasHandlers(self::class, $eventName)) {
            $event = new RegisterComponentTypesEvent(['types' => $types]);
            Event::trigger(self::class, $eventName, $event);
            $types = $event->types;
        }
        return array_values(array_filter($types, 'class_exists'));
    }

    /**
     * Field extractors, tried in order; the first one that returns records for a field wins.
     * Each checks itself whether its plugin is installed, so listing one for a missing plugin is harmless.
     * @var class-string<InterfaceFieldExtractor>[]
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

    /**
     * Element extractors: relations that belong to the element itself rather than to one of its fields
     * (a user's photo, a navigation node's linked element, ...). All of them run.
     * @var class-string<InterfaceElementExtractor>[]
     */
    /** The columns that identify a relation (everything but id/uid/dates). */
    private const ROW_COLUMNS = ['sourceElementId', 'sourceSiteId', 'sourcePrimaryOwnerId', 'targetElementId', 'targetSiteId', 'fieldId', 'customFieldUid', 'type'];


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
        $fields = $element->getFieldLayout()?->getCustomFields() ?? [];

        $baseRecord = new ElementRelationsCacheRecord([
            'sourceElementId' => $element->id,
            'sourceSiteId' => $element->siteId,
            'sourcePrimaryOwnerId' => self::getPrimaryOwnerId($element),
        ]);

        $records = collect($fields)
            ->map(fn(FieldInterface $field) => $field instanceof Field ? self::fieldRelations($field, $element, $baseRecord) : [])
            ->flatten()
            ->merge(collect(self::elementExtractors())
                ->map(fn(string $extractor) => self::safely($extractor, $element, fn() => $extractor::getRelations($element, clone $baseRecord)))
                ->flatten())
            ->filter();

        // Write only when the relations actually changed: keeps row ids/dates stable and skips the delete+insert
        // churn for the common case (an element saved without touching its relations).
        $normalize = fn(array $rows) => collect($rows)
            ->map(fn(array $row) => implode('|', array_map(fn(string $c) => $row[$c] === null ? '' : (string)$row[$c], self::ROW_COLUMNS)))
            ->sort()
            ->values()
            ->all();
        $existing = (new Query())
            ->select(self::ROW_COLUMNS)
            ->from(ElementRelationsCacheRecord::tableName())
            ->where(['sourceElementId' => $element->id, 'sourceSiteId' => $element->siteId])
            ->all();
        if ($normalize($existing) === $normalize($records->map(fn(ElementRelationsCacheRecord $r) => $r->getAttributes(self::ROW_COLUMNS))->all())) {
            return false;
        }

        self::deleteRelationsForElement($element);
        $records->each(fn(ElementRelationsCacheRecord $record) => $record->save());
        return true;
    }

    /**
     * Ids of the elements this element references through one field (or, with `$field = null`, through the element-level
     * extractors), as the extractors read them right now (unsaved values included).
     * @return int[]
     */
    public static function targetIds(ElementInterface $element, ?Field $field): array
    {
        $base = new ElementRelationsCacheRecord(['sourceElementId' => $element->id, 'sourceSiteId' => $element->siteId]);
        $records = $field
            ? self::fieldRelations($field, $element, $base)
            : collect(self::elementExtractors())
                ->map(fn(string $extractor) => self::safely($extractor, $element, fn() => $extractor::getRelations($element, clone $base)))
                ->flatten()
                ->filter()
                ->all();
        return array_values(array_unique(array_map(fn(ElementRelationsCacheRecord $r) => (int)$r->targetElementId, $records)));
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
            $records = self::safely($extractor, $element, fn() => $extractor::getRelations($field, $element, clone $baseRecord));
            if ($records) {
                return $records;
            }
        }
        return [];
    }

    public static function deleteRelationsForElement(ElementInterface $element): void
    {
        ElementRelationsCacheRecord::deleteAll([
            'sourceElementId' => $element->id,
            'sourceSiteId' => $element->siteId,
        ]);
    }

    /**
     * One misbehaving extractor (e.g. after a third-party plugin update) must not fail the whole refresh job,
     * which would leave every other element of the batch without relations. In dev mode the error is rethrown.
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
     * their list (a node or item is titled like the element it links to, so showing it would say nothing).
     */
    private static function getPrimaryOwnerId(ElementInterface $element): int
    {
        while (
            property_exists($element, 'primaryOwnerId')
            && $element->primaryOwnerId
            && $newElement = Craft::$app->getElements()->getElementById($element->primaryOwnerId, null, $element->siteId)
        ) {
            $element = $newElement;
        }
        // `instanceof` on a class of a plugin that isn't installed is simply false
        if ($element instanceof \verbb\navigation\elements\Node && class_exists(\verbb\navigation\elements\Menu::class) && $element->menuId) {
            return (int)$element->menuId;
        }
        if ($element instanceof \verbb\wishlist\elements\Item && $element->listId) {
            return (int)$element->listId;
        }
        return (int)$element->id;
    }
}
