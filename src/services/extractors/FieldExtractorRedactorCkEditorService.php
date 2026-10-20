<?php

declare(strict_types=1);

namespace internetztube\elementRelations\services\extractors;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;

class FieldExtractorRedactorCkEditorService implements InterfaceFieldExtractor, InterfaceFieldReplacer
{
    /** @return list<ElementRelationsCacheRecord>|false */
    public static function getRelations(Field $field, ElementInterface $element, ElementRelationsCacheRecord $baseRecord): array|false
    {
        // a disabled plugin's fields are Craft's MissingField, so the type check covers that too
        if (!($field instanceof \craft\redactor\Field) && !($field instanceof \craft\ckeditor\Field)) {
            return false;
        }

        /** @var \craft\redactor\FieldData|\craft\ckeditor\data\FieldData $fieldData */
        $fieldData = $element->{$field->handle};
        $data = $fieldData?->getRawContent() ?? '';

        $records = [];
        foreach (self::referenceTags($data) as ['id' => $elementId, 'site' => $site]) {
            $siteId = self::siteId($site, (int)$element->siteId);
            if ($siteId === null) {
                continue;
            }
            $record = clone $baseRecord;
            $record->setAttributes([
                'type' => ElementRelationsCacheRecord::TYPE_FIELD,
                'targetElementId' => $elementId,
                'targetSiteId' => $siteId,
                'customFieldUid' => $field->uid,
                'fieldId' => $field->id,
            ]);
            $records[] = $record;
        }
        return $records;
    }

    /**
     * Craft's reference tags in rich text: {entry:12@2:url||fallback}, {asset:7:url}, {entry:12@de}. Matched as a whole,
     * so `||` or braces in ordinary text are not mistaken for one. Only numeric ids count; references by slug or uid are
     * not written by the rich text editors.
     * @return list<array{id: int, site: string}> site is '' (the element's own site), a site id or a site handle
     */
    private static function referenceTags(string $html): array
    {
        preg_match_all('/\{[a-z][\w\\\\]*:(?<id>\d+)(?:@(?<site>[\w-]+))?(?::[^}|]*)?(?:\|\|[^}]*)?\}/i', $html, $matches, PREG_SET_ORDER);
        return array_map(fn(array $match): array => ['id' => (int)$match['id'], 'site' => $match['site'] ?? ''], $matches);
    }

    /** The site of a reference tag: the element's own one, a site id, or a site handle (null when it does not exist). */
    private static function siteId(string $site, int $ownSiteId): ?int
    {
        if ($site === '') {
            return $ownSiteId;
        }
        if (ctype_digit($site)) {
            return (int)$site;
        }
        return Craft::$app->getSites()->getSiteByHandle($site)?->id;
    }

    /**
     * Reference tags: {asset:12:url||https://fallback}, {entry:12@2:title}, {entry:12@de:url||…}, {asset:7:transform:hero||…}:
     * every tag the extractor reads (referenceTags()) that points to `$from`. The id is swapped; the site and the
     * property stay. The `||` fallback is the cached URL of the old element, so it becomes the replacement's URL in the
     * tag's site, or in its own site when it does not exist there (for an asset transform, that transform's URL). Craft's HtmlField drops a tag whose fallback is empty,
     * so the old one is kept when the replacement has no URL.
     */
    public static function replace(Field $field, ElementInterface $element, ElementInterface $from, ElementInterface $to): ?bool
    {
        if (!($field instanceof \craft\redactor\Field) && !($field instanceof \craft\ckeditor\Field)) {
            return null;
        }
        /** @var \craft\redactor\FieldData|\craft\ckeditor\data\FieldData|null $fieldData */
        $fieldData = $element->getFieldValue($field->handle);
        $raw = $fieldData?->getRawContent() ?? '';
        $refHandle = (string)$from::refHandle();
        $ownSiteId = (int)$element->siteId;
        $new = preg_replace_callback(
            '/\{' . preg_quote($refHandle, '/') . ':' . $from->id . '(?<site>@[\w-]+)?(?<property>:[^}|]*)?(?:\|\|(?<fallback>[^}]*))?\}/i',
            function (array $m) use ($refHandle, $to, $ownSiteId): string {
                $site = (string)($m['site'] ?? '');
                $property = (string)($m['property'] ?? '');
                $fallback = $m['fallback'] ?? null;
                if ($fallback !== null) {
                    $fallback = self::fallbackUrl($to, $property, self::siteId(ltrim($site, '@'), $ownSiteId)) ?? $fallback;
                }
                return '{' . $refHandle . ':' . $to->id . $site . $property . ($fallback !== null ? '||' . $fallback : '') . '}';
            },
            $raw,
            -1,
            $count,
            PREG_UNMATCHED_AS_NULL,
        );
        if (!$count || $new === null) {
            return false;
        }
        $element->setFieldValue($field->handle, $new);
        return true;
    }

    /**
     * The URL a reference tag's fallback should hold for `$element` in a site: `:url` its URL, `:transform:<handle>`
     * (or `:url:<handle>`) of an asset that transform's URL. Null when there is none (the old fallback is kept).
     */
    private static function fallbackUrl(ElementInterface $element, string $property, ?int $siteId): ?string
    {
        if ($siteId !== null && $siteId !== (int)$element->siteId) {
            $element = Craft::$app->getElements()->getElementById((int)$element->id, $element::class, $siteId) ?? $element;
        }
        $parts = explode(':', ltrim($property, ':'));
        try {
            if ($element instanceof \craft\elements\Asset && in_array($parts[0], ['url', 'transform'], true) && isset($parts[1]) && $parts[1] !== '') {
                return $element->getUrl($parts[1]) ?: null;
            }
            return $parts[0] === 'url' ? ($element->getUrl() ?: null) : null;
        } catch (\Throwable) {
            return null; // e.g. an unknown transform: keep the old fallback
        }
    }
}
