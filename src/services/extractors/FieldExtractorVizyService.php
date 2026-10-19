<?php

namespace internetztube\elementRelations\services\extractors;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Json;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;
use internetztube\elementRelations\services\ExtractorService;
use internetztube\elementRelations\services\ReplaceService;

/**
 * Vizy (verbb/vizy). The field stores ProseMirror-style node JSON:
 * - link marks keep the element in `attrs.href` (`https://…/page#entry:12@1`), images in `attrs.src`
 *   (`https://…/img.png#asset:3:url`) and an image's own link in `attrs.url`; `{entry:12@1:url}` ref tags are matched too.
 * - `vizyBlock` nodes hold inner field values (keyed by layout element uid). They are exposed as a synthetic
 *   `verbb\vizy\elements\Block` element, so the regular field extractors run on it (relation fields, links, nested Vizy, …).
 * All relations are attributed to the Vizy field of the owner element.
 */
class FieldExtractorVizyService implements InterfaceFieldExtractor, InterfaceFieldReplacer
{
    private const REF_PATTERN = '/(?:#|%23|\{)(\w+):(\d+)(?:@(\d+))?/';

    /**
     * @param Field $field
     * @param ElementInterface $element
     * @param ElementRelationsCacheRecord $baseRecord
     * @return ElementRelationsCacheRecord[]|false
     */
    public static function getRelations(Field $field, ElementInterface $element, ElementRelationsCacheRecord $baseRecord): array|false
    {
        if (!Craft::$app->getPlugins()->isPluginEnabled('vizy') || !($field instanceof \verbb\vizy\fields\VizyField)) {
            return false;
        }

        $value = $element->getFieldValue($field->handle);
        if (!($value instanceof \verbb\vizy\models\NodeCollection)) {
            return false;
        }

        $targets = [];
        foreach ($value->getRawNodes() as $node) {
            foreach (self::refs($node) as [, $refHandle, $id, $siteId]) {
                $type = Craft::$app->getElements()->getElementTypeByRefHandle($refHandle);
                $target = $type ? Craft::$app->getElements()->getElementById((int)$id, $type, $siteId ?: $element->siteId) : null;
                if ($target) {
                    $targets[] = [$target->id, $target->siteId];
                }
            }
        }

        foreach ($value->getNodes() as $node) {
            if ($node instanceof \verbb\vizy\nodes\VizyBlock) {
                foreach (self::blockRelations($node->getBlockElement($element), $baseRecord) as $record) {
                    $targets[] = [$record->targetElementId, $record->targetSiteId];
                }
            }
        }

        return collect($targets)
            ->unique(fn(array $target) => implode('-', $target))
            ->map(function (array $target) use ($field, $baseRecord) {
                $record = clone $baseRecord;
                $record->setAttributes([
                    'type' => ElementRelationsCacheRecord::TYPE_FIELD,
                    'targetElementId' => $target[0],
                    'targetSiteId' => $target[1],
                    'customFieldUid' => $field->uid,
                    'fieldId' => $field->id,
                ]);
                return $record;
            })
            ->values()
            ->all();
    }

    /**
     * Works on the raw nodes as the control panel posts them (`serializeValue()`: block fields keyed by handle):
     * ref ids in `href`/`src`/`url` and an image node's `attrs.id` are rewritten, block fields go through the
     * regular field replacers on the synthetic block element and their new values are written back into the node.
     */
    public static function replace(Field $field, ElementInterface $element, ElementInterface $from, ElementInterface $to): ?bool
    {
        if (!Craft::$app->getPlugins()->isPluginEnabled('vizy') || !($field instanceof \verbb\vizy\fields\VizyField)) {
            return null;
        }
        $value = $element->getFieldValue($field->handle);
        if (!($value instanceof \verbb\vizy\models\NodeCollection)) {
            return false;
        }

        $nodes = Json::decode($field->serializeValue($value, $element));
        $count = 0;
        foreach ($nodes as $i => $node) {
            $nodes[$i] = self::rewrite($node, $from, $to, $count);
        }
        foreach ($value->getNodes() as $i => $node) {
            if (!($node instanceof \verbb\vizy\nodes\VizyBlock)) {
                continue;
            }
            $block = $node->getBlockElement($element);
            foreach ($block->getFieldLayout()?->getCustomFields() ?? [] as $innerField) {
                if ($innerField instanceof Field && ReplaceService::replaceInField($innerField, $block, $from, $to)) {
                    $nodes[$i]['attrs']['values']['content']['fields'][$innerField->handle] = $innerField->serializeValue($block->getFieldValue($innerField->handle), $block);
                    $count++;
                }
            }
        }
        if (!$count) {
            return false;
        }
        $element->setFieldValue($field->handle, $nodes);
        return true;
    }

    /** Inverse of refs(): `#entry:12@1`, `%23asset:3:url`, `{entry:12}` in `href`/`src`/`url`, plus `attrs.id` of an image node. */
    private static function rewrite(mixed $node, ElementInterface $from, ElementInterface $to, int &$count): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        foreach (['href', 'src', 'url'] as $attr) {
            if (is_string($node['attrs'][$attr] ?? null)) {
                $node['attrs'][$attr] = preg_replace('/((?:#|%23|\{)' . preg_quote($from::refHandle(), '/') . ':)' . $from->id . '(?!\d)/', '${1}' . $to->id, $node['attrs'][$attr], -1, $n);
                $count += $n;
            }
        }
        if (($node['type'] ?? null) === 'image' && (int)($node['attrs']['id'] ?? 0) === $from->id && $from instanceof \craft\elements\Asset) {
            $node['attrs']['id'] = $to->id;
            $count++;
        }
        foreach (['marks', 'content'] as $key) {
            foreach ((array)($node[$key] ?? []) as $j => $child) {
                $node[$key][$j] = self::rewrite($child, $from, $to, $count);
            }
        }
        return $node;
    }

    /** Ref matches in `href`/`src`/`url` attributes of a raw node, its marks and its child nodes (not block field values). */
    private static function refs(mixed $node): array
    {
        if (!is_array($node)) {
            return [];
        }
        $refs = [];
        foreach (['href', 'src', 'url'] as $attr) {
            $url = $node['attrs'][$attr] ?? null;
            if (is_string($url) && preg_match_all(self::REF_PATTERN, $url, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) {
                $refs = [...$refs, ...$matches];
            }
        }
        foreach ([...(array)($node['marks'] ?? []), ...(array)($node['content'] ?? [])] as $child) {
            $refs = [...$refs, ...self::refs($child)];
        }
        return $refs;
    }

    /**
     * Runs the regular field extractors on the inner fields of a Vizy block.
     * @return ElementRelationsCacheRecord[]
     */
    private static function blockRelations(ElementInterface $block, ElementRelationsCacheRecord $baseRecord): array
    {
        $records = [];
        foreach ($block->getFieldLayout()?->getCustomFields() ?? [] as $innerField) {
            if ($innerField instanceof Field) {
                $records = [...$records, ...ExtractorService::fieldRelations($innerField, $block, $baseRecord)];
            }
        }
        return array_filter($records);
    }
}
