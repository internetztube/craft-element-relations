<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Extractors;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Field\Contracts\TracksReferencesFieldInterface;
use CraftCms\Cms\Field\Field;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Sites;
use Internetztube\ElementRelations\Models\CacheRecord;

/**
 * Text fields holding Craft's reference tags ({entry:12@2:url||fallback}, {asset:7:url}): every field Craft marks as
 * tracking references (TracksReferencesFieldInterface) whose stored value is a string, such as the Markdown field.
 * Link fields, which track references too, have their own extractor (it runs first).
 */
class FieldExtractorRichTextService implements InterfaceFieldExtractor, InterfaceFieldReplacer
{
    /** @return list<CacheRecord>|false */
    public static function getRelations(Field $field, ElementInterface $element, CacheRecord $baseRecord): array|false
    {
        $raw = self::raw($field, $element);
        if ($raw === null) {
            return false;
        }
        $records = [];
        foreach (self::referenceTags($raw) as ['id' => $elementId, 'site' => $site]) {
            $siteId = self::siteId($site, (int)$element->siteId);
            if ($siteId === null) {
                continue;
            }
            $records[] = (clone $baseRecord)->fill([
                'type' => CacheRecord::TYPE_FIELD,
                'targetElementId' => $elementId,
                'targetSiteId' => $siteId,
                'customFieldUid' => $field->uid,
                'fieldId' => (int)$field->id,
            ]);
        }
        return $records;
    }

    /** The field's stored text, null when it is not a text field with reference tags. */
    private static function raw(Field $field, ElementInterface $element): ?string
    {
        if (!$field instanceof TracksReferencesFieldInterface) {
            return null;
        }
        $value = $field->serializeValue($element->getFieldValue((string)$field->handle), $element);
        return is_string($value) || $value === null ? (string)$value : null;
    }

    /**
     * Craft's reference tags in text: {entry:12@2:url||fallback}, {asset:7:url}, {entry:12@de}. Matched as a whole,
     * so `||` or braces in ordinary text are not mistaken for one. Only numeric ids count; references by slug or uid are
     * not written by the editors.
     * @return list<array{id: int, site: string}> site is '' (the element's own site), a site id or a site handle
     */
    private static function referenceTags(string $text): array
    {
        preg_match_all('/\{[a-z][\w\\\\]*:(?<id>\d+)(?:@(?<site>[\w-]+))?(?::[^}|]*)?(?:\|\|[^}]*)?\}/i', $text, $matches, PREG_SET_ORDER);
        return array_values(array_map(fn(array $match): array => ['id' => (int)$match['id'], 'site' => $match['site'] ?? ''], $matches));
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
        return Sites::getSiteByHandle($site)?->id;
    }

    /**
     * Every tag that points to `$from`: the id is swapped; the site and the property stay. The `||` fallback is the
     * cached URL of the old element, so it becomes the replacement's URL in the tag's site, or in its own site when it
     * does not exist there (for an asset transform, that transform's URL). The old one is kept when the replacement
     * has no URL.
     */
    public static function replace(Field $field, ElementInterface $element, ElementInterface $from, ElementInterface $to): ?bool
    {
        $raw = self::raw($field, $element);
        if ($raw === null) {
            return null;
        }
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
        $element->setFieldValue((string)$field->handle, $new);
        return true;
    }

    /**
     * The URL a reference tag's fallback should hold for `$element` in a site: `:url` its URL, `:transform:<handle>`
     * (or `:url:<handle>`) of an asset that transform's URL. Null when there is none (the old fallback is kept).
     */
    private static function fallbackUrl(ElementInterface $element, string $property, ?int $siteId): ?string
    {
        if ($siteId !== null && $siteId !== (int)$element->siteId) {
            $element = Elements::getElementById((int)$element->id, $element::class, $siteId) ?? $element;
        }
        $parts = explode(':', ltrim($property, ':'));
        try {
            if ($element instanceof Asset && in_array($parts[0], ['url', 'transform'], true) && isset($parts[1]) && $parts[1] !== '') {
                return $element->getUrl($parts[1]) ?: null;
            }
            return $parts[0] === 'url' ? ($element->getUrl() ?: null) : null;
        } catch (\Throwable) {
            return null; // e.g. an unknown transform: keep the old fallback
        }
    }
}
