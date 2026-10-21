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
        if (!(Craft::$app->plugins->isPluginEnabled('redactor') || Craft::$app->plugins->isPluginEnabled('ckeditor'))) {
            return false;
        }
        
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
     * Reference tags: {asset:12:url||https://fallback}, {entry:12@2:title}, ... The `||` fallback is the cached URL of the
     * old element; Craft's HtmlField drops a tag whose fallback is empty, so the old one is kept when the replacement
     * has no URL.
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
        $new = preg_replace_callback(
            '/\\{' . preg_quote($refHandle, '/') . ':' . $from->id . '(@\\d+)?(?::([\\w.]+))?(?:\\|\\|([^}]*))?\\}/',
            function (array $m) use ($refHandle, $to): string {
                $site = $m[1] ?? '';
                $property = isset($m[2]) && $m[2] !== '' ? ':' . $m[2] : '';
                $fallback = $m[3] ?? null;
                if ($fallback !== null && ($m[2] ?? '') === 'url') {
                    $fallback = $to->getUrl() ?: $fallback;
                }
                return '{' . $refHandle . ':' . $to->id . $site . $property . ($fallback !== null ? '||' . $fallback : '') . '}';
            },
            $raw,
            -1,
            $count,
        );
        if (!$count) {
            return false;
        }
        $element->setFieldValue($field->handle, $new);
        return true;
    }
}
