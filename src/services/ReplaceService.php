<?php

namespace internetztube\elementRelations\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\db\Query;
use craft\db\Table;
use craft\fields\BaseRelationField;
use internetztube\elementRelations\models\RelationsModel;

/**
 * Pro: replaces every usage of one element by another of the same type, going through the relating elements' field
 * values and Craft's save path (so the cache, search index and revisions stay consistent).
 *
 * Handled: relational fields (Entries, Assets, Categories, Tags, Users, plugin fields extending BaseRelationField,
 * also inside Matrix/nested entries), CKEditor/Redactor reference tags, SEOmatic SEO/Twitter/OG images.
 * Everything else (link plugins, element-level usages such as user photos, plugin settings) is reported as "manual".
 */
class ReplaceService
{
    /** ponytail: synchronous; a queue job for very large sites comes when someone hits the limit */
    public const MAX_SOURCES = 500;

    /**
     * @param int[]|null $onlySourceIds restrict to these relating elements as listed in the field (top-level owners;
     * nested entries, navigation nodes etc. are matched through their owner); null = everywhere
     * @return array{replaced: int, skipped: string[]}
     */
    public static function replace(ElementInterface $from, ElementInterface $to, ?array $onlySourceIds = null): array
    {
        if ($from::class !== $to::class || $from->id === $to->id) {
            throw new \InvalidArgumentException('Replacement must be a different element of the same type.');
        }

        $rows = (new Query())
            ->select(['sourceElementId', 'sourceSiteId', 'fieldId', 'type'])
            ->distinct()
            ->from('{{%elementrelations_cache}}')
            ->where(['targetElementId' => $from->id])
            ->andWhere(['not', ['sourceElementId' => null]]) // non-element usages are reported below
            ->andFilterWhere(['sourcePrimaryOwnerId' => $onlySourceIds])
            ->limit(self::MAX_SOURCES)
            ->all();
        $types = (new Query())->select(['id', 'type'])->from(Table::ELEMENTS)->where(['id' => array_column($rows, 'sourceElementId')])->pairs();

        $bySource = [];
        foreach ($rows as $row) {
            $bySource["{$row['sourceElementId']}:{$row['sourceSiteId']}"][] = $row;
        }

        $replaced = 0;
        $skipped = [];
        foreach ($bySource as $key => $sourceRows) {
            [$id, $siteId] = array_map('intval', explode(':', $key));
            $type = $types[$id] ?? null;
            $element = is_subclass_of($type, ElementInterface::class)
                ? $type::find()->id($id)->siteId($siteId)->status(null)->drafts(null)->provisionalDrafts(null)->one()
                : null;
            if (!$element) {
                continue;
            }
            // in the control panel, only what the current user may save (console runs unrestricted)
            $user = Craft::$app->getRequest()->getIsConsoleRequest() ? null : Craft::$app->getUser()->getIdentity();
            if ($user && !$element->canSave($user)) {
                $skipped[] = (string)$element . ' (' . Craft::t('element-relations', 'replace-no-permission') . ')';
                continue;
            }

            $changed = false;
            foreach ($sourceRows as $row) {
                $field = $row['fieldId'] ? Craft::$app->getFields()->getFieldById((int)$row['fieldId']) : null;
                if ($field instanceof Field && self::replaceInField($field, $element, $from, $to)) {
                    $changed = true;
                } else {
                    $skipped[] = $field ? "{$field->name}: $element" : (string)$element;
                }
            }
            if ($changed) {
                if (Craft::$app->getElements()->saveElement($element)) {
                    // the save event queues a refresh; do it now too, so the caller's index is consistent immediately
                    ExtractorService::refreshRelationsForElement($element);
                    $replaced++;
                } else {
                    $skipped[] = (string)$element . ' (' . implode(' ', $element->getFirstErrors()) . ')';
                }
            }
        }

        if ($onlySourceIds === null) {
            foreach ((new RelationsModel($from->id, $from->siteId))->getGlobalUsages() as $usage) {
                $skipped[] = $usage->label;
            }
        }

        return ['replaced' => $replaced, 'skipped' => array_values(array_unique($skipped))];
    }

    /** Swaps `$from` for `$to` in the element's value of this field; false when the field type is not supported. */
    public static function replaceInField(Field $field, ElementInterface $element, ElementInterface $from, ElementInterface $to): bool
    {
        $handle = $field->handle;

        if ($field instanceof BaseRelationField) {
            $ids = array_map('intval', $element->getFieldValue($handle)->ids());
            if (!in_array($from->id, $ids, true)) {
                return false;
            }
            $ids = array_values(array_unique(array_map(fn(int $id) => $id === $from->id ? $to->id : $id, $ids)));
            $element->setFieldValue($handle, $ids);
            return true;
        }

        if (is_a($field, 'craft\ckeditor\Field') || is_a($field, 'craft\redactor\Field')) {
            // Reference tags: {asset:12:url||https://fallback}, {entry:12@2:title}, ... The `||` fallback is the cached
            // URL of the old element; Craft's HtmlField drops a tag with an empty fallback, so keep the old one when
            // the replacement has no URL.
            $raw = $element->getFieldValue($handle)?->getRawContent() ?? '';
            $refHandle = preg_quote($from::refHandle(), '/');
            $new = preg_replace_callback(
                '/\{' . $refHandle . ':' . $from->id . '(@\d+)?(?::([\w.]+))?(?:\|\|([^}]*))?\}/',
                function (array $m) use ($from, $to): string {
                    $site = $m[1] ?? '';
                    $property = isset($m[2]) && $m[2] !== '' ? ':' . $m[2] : '';
                    $fallback = $m[3] ?? null;
                    if ($fallback !== null && ($m[2] ?? '') === 'url') {
                        $fallback = $to->getUrl() ?: $fallback;
                    }
                    return '{' . $from::refHandle() . ':' . $to->id . $site . $property . ($fallback !== null ? '||' . $fallback : '') . '}';
                },
                $raw,
                -1,
                $count,
            );
            if (!$count) {
                return false;
            }
            $element->setFieldValue($handle, $new);
            return true;
        }

        if (is_a($field, 'nystudio107\seomatic\fields\SeoSettings')) {
            $value = $field->serializeValue($element->getFieldValue($handle), $element);
            $count = 0;
            foreach (['seoImageIds', 'twitterImageIds', 'ogImageIds'] as $key) {
                $ids = (array)($value['metaBundleSettings'][$key] ?? []);
                $value['metaBundleSettings'][$key] = array_values(array_unique(array_map(function ($id) use ($from, $to, &$count) {
                    if ((int)$id === $from->id) {
                        $count++;
                        return (string)$to->id;
                    }
                    return $id;
                }, $ids)));
            }
            if (!$count) {
                return false;
            }
            $element->setFieldValue($handle, $value);
            return true;
        }

        return false;
    }
}
