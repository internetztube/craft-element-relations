<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Services;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Contracts\NestedElementInterface;
use CraftCms\Cms\Field\Field;
use CraftCms\Cms\Support\Facades\Elements;
use Illuminate\Support\Facades\DB;
use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Elements\Queries\UsageQuery;
use Internetztube\ElementRelations\Events\ElementExtractorsResolving;
use Internetztube\ElementRelations\Events\FieldExtractorsResolving;
use Internetztube\ElementRelations\Extractors;
use Internetztube\ElementRelations\Extractors\InterfaceElementExtractor;
use Internetztube\ElementRelations\Extractors\InterfaceFieldExtractor;
use Internetztube\ElementRelations\Models\CacheRecord;
use Internetztube\ElementRelations\Models\RelationsModel;

class ExtractorService
{
    /** @var list<class-string<InterfaceFieldExtractor>>|null */
    private static ?array $fieldExtractors = null;
    /** @var list<class-string<InterfaceElementExtractor>>|null */
    private static ?array $elementExtractors = null;

    /** @return list<class-string<InterfaceFieldExtractor>> the built-in field extractors plus registered ones (installed classes only) */
    public static function fieldExtractors(): array
    {
        if (self::$fieldExtractors === null) {
            event($event = new FieldExtractorsResolving(self::FIELD_EXTRACTORS));
            self::$fieldExtractors = array_values(array_filter($event->types, 'class_exists'));
        }
        return self::$fieldExtractors;
    }

    /** @return list<class-string<InterfaceElementExtractor>> */
    public static function elementExtractors(): array
    {
        if (self::$elementExtractors === null) {
            event($event = new ElementExtractorsResolving(self::ELEMENT_EXTRACTORS));
            self::$elementExtractors = array_values(array_filter($event->types, 'class_exists'));
        }
        return self::$elementExtractors;
    }

    /** Forgets the registered extractors (tests, or plugins registering late). */
    public static function reset(): void
    {
        self::$fieldExtractors = self::$elementExtractors = null;
    }

    /**
     * Field extractors, tried in order; the first one that returns relations for a field wins.
     * @var list<class-string<InterfaceFieldExtractor>>
     */
    public const FIELD_EXTRACTORS = [
        // core relation fields (also picks up drafts of the targets)
        Extractors\FieldExtractorBaseRelationService::class,
        // the core Link field (it tracks references, so before the text fields)
        Extractors\FieldExtractorLinkService::class,
        // text fields with reference tags (Markdown)
        Extractors\FieldExtractorRichTextService::class,
        // everything else that writes Craft's `relations` table (plugin fields)
        Extractors\FieldExtractorRelationalFieldService::class,
    ];

    /**
     * Element extractors: relations that belong to the element itself rather than to one of its fields (a user's
     * photo). All of them run.
     * @var list<class-string<InterfaceElementExtractor>>
     */
    public const ELEMENT_EXTRACTORS = [
        Extractors\ElementExtractorUserPhotoService::class,
    ];

    /** Refreshes the element's cache rows; true when they changed. */
    public static function refreshRelationsForElement(ElementInterface $element): bool
    {
        self::$owners = self::$inBatch ? self::$owners : [];
        [$ownerId, $ofRevision] = self::primaryOwner($element);
        // revisions are never shown as usages, and neither are the nested elements (Matrix entries) Craft keeps for
        // them, which reach this through other paths than the save event (owner trees, full rebuilds)
        if ($element->getIsRevision() || $ofRevision) {
            RelationsModel::forgetPrefetched();
            return DB::table(CacheRecord::TABLE)->where('sourceElementId', $element->id)->where('sourceSiteId', $element->siteId)->delete() > 0;
        }

        $fields = $element->getFieldLayout()?->getCustomFields() ?? [];

        $baseRecord = new CacheRecord([
            'sourceElementId' => (int)$element->id,
            'sourceSiteId' => (int)$element->siteId,
            'sourcePrimaryOwnerId' => $ownerId,
        ]);

        $records = [];
        foreach ($fields as $field) {
            if ($field instanceof Field) {
                array_push($records, ...self::fieldRelations($field, $element, $baseRecord));
            }
        }
        array_push($records, ...self::elementRelations($element, $baseRecord));

        // Write only when the relations actually changed: keeps row ids/dates stable and skips the delete+insert
        // churn for the common case (an element saved without touching its relations).
        $normalize = fn(iterable $rows): array => collect($rows)
            ->map(fn(array $row): string => implode('|', array_map(fn(string $column): string => $row[$column] === null ? '' : (string)$row[$column], CacheRecord::ROW_COLUMNS)))
            ->sort()
            ->values()
            ->all();
        $existing = DB::table(CacheRecord::TABLE)
            ->select(CacheRecord::ROW_COLUMNS)
            ->where('sourceElementId', $element->id)
            ->where('sourceSiteId', $element->siteId)
            ->get()
            ->map(fn(object $row): array => (array)$row);
        $rows = array_map(fn(CacheRecord $record): array => $record->row(), $records);
        if ($normalize($existing) === $normalize($rows)) {
            return false;
        }

        self::deleteRelationsForElement($element);
        // one INSERT for all of them
        if ($rows) {
            $now = now();
            DB::table(CacheRecord::TABLE)->insert(array_map(fn(array $row): array => $row + [
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => \CraftCms\Cms\Support\Str::uuid()->toString(),
            ], $rows));
        }
        UsageQuery::resetPermissionCache();
        RelationsModel::forgetPrefetched();
        return true;
    }

    /**
     * Ids of the elements this element references through one field (or, with `$field = null`, through the element-level
     * extractors), as the extractors read them right now (unsaved values included).
     * @return list<int>
     */
    public static function targetIds(ElementInterface $element, ?Field $field): array
    {
        $base = new CacheRecord(['sourceElementId' => (int)$element->id, 'sourceSiteId' => (int)$element->siteId]);
        $records = $field ? self::fieldRelations($field, $element, $base) : self::elementRelations($element, $base);
        return array_values(array_unique(array_map(fn(CacheRecord $record): int => (int)$record->targetElementId, $records)));
    }

    /** Whether an element-level extractor (user photo, ...) applies to this element. */
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
     * Public so extractors of container fields can run it on their inner fields.
     * @return list<CacheRecord>
     */
    public static function fieldRelations(Field $field, ElementInterface $element, CacheRecord $baseRecord): array
    {
        foreach (self::fieldExtractors() as $extractor) {
            $records = self::safely($extractor, $element, fn(): mixed => $extractor::getRelations($field, $element, clone $baseRecord));
            if (is_array($records) && $records) {
                return array_values(array_filter($records, fn(mixed $record): bool => $record instanceof CacheRecord));
            }
        }
        return [];
    }

    /**
     * What the element-level extractors read from the element (all of them run).
     * @return list<CacheRecord>
     */
    private static function elementRelations(ElementInterface $element, CacheRecord $baseRecord): array
    {
        $records = [];
        foreach (self::elementExtractors() as $extractor) {
            $result = self::safely($extractor, $element, fn(): mixed => $extractor::getRelations($element, clone $baseRecord));
            foreach (is_array($result) ? $result : [$result] as $record) {
                if ($record instanceof CacheRecord) {
                    $records[] = $record;
                }
            }
        }
        return $records;
    }

    /**
     * Drops the rows of an element that is gone (deleted, or a nested element removed from its owner), in every site
     * or in one (deleted for that site): its own rows and those of its nested elements, of which it is the owner.
     */
    public static function forgetElement(ElementInterface $element, ?int $siteId = null): void
    {
        UsageQuery::resetPermissionCache();
        RelationsModel::forgetPrefetched();
        DB::table(CacheRecord::TABLE)
            ->where(fn($query) => $query->where('sourceElementId', $element->id)->orWhere('sourcePrimaryOwnerId', $element->id))
            ->when($siteId !== null, fn($query) => $query->where('sourceSiteId', $siteId))
            ->delete();
    }

    public static function deleteRelationsForElement(ElementInterface $element): void
    {
        UsageQuery::resetPermissionCache();
        RelationsModel::forgetPrefetched();
        DB::table(CacheRecord::TABLE)->where('sourceElementId', $element->id)->where('sourceSiteId', $element->siteId)->delete();
    }

    /**
     * One misbehaving extractor (e.g. after a plugin update) must not fail the whole refresh job, which would leave
     * every other element of the batch without relations. In debug mode the error is rethrown.
     * @param callable(): mixed $fn
     * @return mixed what `$fn` returns, false when it failed
     */
    private static function safely(string $extractor, ElementInterface $element, callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            ElementRelations::log()->error(sprintf('%s failed for element #%s: %s', $extractor, $element->id, $e->getMessage()));
            if (config('app.debug')) {
                throw $e;
            }
            return false;
        }
    }

    /**
     * The element an editor should see as "where it's used": nested elements (Matrix entries, content blocks,
     * addresses, variants) resolve to their top-level owner. With whether that element is a revision.
     * @return array{int, bool}
     */
    private static function primaryOwner(ElementInterface $element): array
    {
        if ($element instanceof NestedElementInterface && $element->getPrimaryOwnerId()) {
            // the blocks of one owner share it: load it once per batch (see inBatch())
            $key = $element->getPrimaryOwnerId() . ':' . $element->siteId;
            if (!array_key_exists($key, self::$owners)) {
                $owner = Elements::getElementById($element->getPrimaryOwnerId(), null, $element->siteId);
                self::$owners[$key] = $owner ? self::primaryOwner($owner) : null;
            }
            if (self::$owners[$key] !== null) {
                return self::$owners[$key];
            }
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
