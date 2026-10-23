<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Extractors;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Field\Field;
use CraftCms\Cms\Field\Link;
use Internetztube\ElementRelations\Models\CacheRecord;

/**
 * Craft's Link field: its serialized value is ['type' => 'entry', 'value' => '{entry:12@1:url}', ...attributes]. The
 * reference tag names the element and the site it links to.
 */
class FieldExtractorLinkService implements InterfaceFieldExtractor, InterfaceFieldReplacer
{
    /** @return list<CacheRecord>|false */
    public static function getRelations(Field $field, ElementInterface $element, CacheRecord $baseRecord): array|false
    {
        if (!$field instanceof Link) {
            return false;
        }
        $tag = self::tag($field, $element);
        if ($tag === null || !preg_match('/^\{[a-z][\w\\\\]*:(\d+)(?:@(\d+))?:url\}$/i', $tag, $match)) {
            return [];
        }
        return [(clone $baseRecord)->fill([
            'type' => CacheRecord::TYPE_FIELD,
            'targetElementId' => (int)$match[1],
            'targetSiteId' => isset($match[2]) ? (int)$match[2] : (int)$element->siteId,
            'customFieldUid' => $field->uid,
            'fieldId' => (int)$field->id,
        ])];
    }

    /**
     * The reference tag's id is swapped, everything else (label, target, site) stays. Craft's own
     * Link::replaceReferences() would keep only the type and value.
     */
    public static function replace(Field $field, ElementInterface $element, ElementInterface $from, ElementInterface $to): ?bool
    {
        if (!$field instanceof Link) {
            return null;
        }
        $value = $field->serializeValue($element->getFieldValue((string)$field->handle), $element);
        if (!is_array($value) || !is_string($value['value'] ?? null)) {
            return false;
        }
        $value['value'] = preg_replace(
            '/^\{' . preg_quote((string)$from::refHandle(), '/') . ':' . $from->id . '(@\d+)?:url\}$/',
            '{' . $to::refHandle() . ':' . $to->id . '$1:url}',
            $value['value'],
            -1,
            $count,
        );
        if (!$count) {
            return false;
        }
        $element->setFieldValue((string)$field->handle, $value);
        return true;
    }

    /** The stored reference tag (`{entry:12@1:url}`), null for a link to a URL, email address, … */
    private static function tag(Link $field, ElementInterface $element): ?string
    {
        $value = $field->serializeValue($element->getFieldValue((string)$field->handle), $element);
        return is_array($value) && is_string($value['value'] ?? null) && str_starts_with($value['value'], '{') ? $value['value'] : null;
    }
}
