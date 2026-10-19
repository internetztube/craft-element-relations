<?php

namespace internetztube\elementRelations\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\FieldInterface;
use craft\db\Query;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;
use internetztube\elementRelations\services\extractors\InterfaceElementExtractor;
use internetztube\elementRelations\services\extractors\InterfaceFieldExtractor;

class ExtractorService
{
    /**
     * Field extractors, tried in order; the first one that returns records for a field wins.
     * Each checks itself whether its plugin is installed, so listing one for a missing plugin is harmless.
     * @var class-string<InterfaceFieldExtractor>[]
     */
    private const FIELD_EXTRACTORS = [
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

    /** Element classes the ELEMENT_EXTRACTORS read attributes from (not custom fields), see hasElementExtractor(). */
    private const ELEMENT_EXTRACTOR_ELEMENT_TYPES = [
        \craft\elements\User::class,
        'verbb\\navigation\\elements\\Node',
        'verbb\\wishlist\\elements\\Item',
        'verbb\\formie\\elements\\Form',
        'verbb\\formie\\elements\\Submission',
    ];

    private const ELEMENT_EXTRACTORS = [
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
            ->merge(collect(array_filter(self::ELEMENT_EXTRACTORS, 'class_exists'))
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

    /** Whether an element-level extractor (user photo, navigation node, ...) applies to this element. */
    public static function hasElementExtractor(ElementInterface $element): bool
    {
        foreach (self::ELEMENT_EXTRACTOR_ELEMENT_TYPES as $type) {
            if (is_a($element, $type)) {
                return true;
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
        foreach (array_filter(self::FIELD_EXTRACTORS, 'class_exists') as $extractor) {
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
