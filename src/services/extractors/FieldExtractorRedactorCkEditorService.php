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

        /** @var list<ElementRelationsCacheRecord> */
        return collect(explode("{", $data))
            ->map(fn (string $row): array => explode('}', $row))
            ->flatten()
            ->filter(fn (string $row): bool => str_contains($row, '||'))
            ->map(fn (string $row): string => explode('||', $row)[0])
            ->map(fn (string $row): string => explode(':', $row)[1])
            ->map(function (string $row) use ($element, $baseRecord, $field): ?ElementRelationsCacheRecord {
                $siteId = str_contains($row, '@') ? explode('@', $row)[1] : $element->siteId;
                $elementId = explode('@', $row)[0];

                if (!$elementId || !$siteId) {
                    return null;
                }

                $record = clone $baseRecord;
                $record->setAttributes([
                    'type' => ElementRelationsCacheRecord::TYPE_FIELD,
                    'targetElementId' => $elementId,
                    'targetSiteId' => $siteId,
                    'customFieldUid' => $field->uid,
                    'fieldId' => $field->id,
                ]);
                return $record;
            })
            ->filter()
            ->values()
            ->all();
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
