<?php

namespace internetztube\elementRelations\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\db\Query;
use craft\db\Table;
use internetztube\elementRelations\models\RelationsModel;
use internetztube\elementRelations\services\extractors\InterfaceElementReplacer;
use internetztube\elementRelations\services\extractors\InterfaceFieldReplacer;

/**
 * Pro: replaces every usage of one element by another of the same type, going through the relating elements' field
 * values and Craft's save path (so the cache, search index and revisions stay consistent).
 *
 * Each extractor is also the replacer for its field or element type (InterfaceFieldReplacer / InterfaceElementReplacer),
 * so whatever can be detected can be swapped. Usages without a replacer (plugin settings) are reported as "manual".
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
        $saved = []; // element ids rewritten (in whichever site)
        $pending = []; // "not rewritten" per element id; decided once every site has been seen
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
                $done = $field instanceof Field
                    ? self::replaceInField($field, $element, $from, $to)
                    : self::replaceInElement($element, $from, $to);
                if ($done) {
                    $changed = true;
                } else {
                    $pending[$id][] = $field ? "{$field->name}: $element" : (string)$element;
                }
            }
            if ($changed) {
                if (Craft::$app->getElements()->saveElement($element)) {
                    $replaced++;
                    $saved[$id] = true; // refreshed in every site below (the save event's queued refresh comes later)
                } else {
                    $skipped[] = (string)$element . ' (' . implode(' ', $element->getFirstErrors()) . ')';
                }
            }
        }

        // Data shared across sites (non-translatable fields, Formie forms) is swapped by whichever site got saved first;
        // the other sites then hold no reference anymore and Craft fires no save event for them. So: an element saved
        // in any site is refreshed in every site it exists in, and only elements saved nowhere count as skipped.
        foreach ($pending as $id => $labels) {
            if (!isset($saved[$id])) {
                $skipped = [...$skipped, ...$labels];
            }
        }
        foreach (array_keys($saved) as $id) {
            $type = $types[$id] ?? null;
            foreach ($type::find()->id($id)->site('*')->status(null)->drafts(null)->provisionalDrafts(null)->all() as $siteElement) {
                ExtractorService::refreshRelationsForElement($siteElement);
            }
        }

        if ($onlySourceIds === null) {
            foreach ((new RelationsModel($from->id, $from->siteId))->getGlobalUsages() as $usage) {
                $skipped[] = $usage->label;
            }
        }

        return ['replaced' => $replaced, 'skipped' => array_values(array_unique($skipped))];
    }

    /**
     * Swaps `$from` for `$to` in the element's value of this field. The field extractors double as replacers
     * (InterfaceFieldReplacer); the first one that recognises the field type decides. False = not supported / no change.
     */
    public static function replaceInField(Field $field, ElementInterface $element, ElementInterface $from, ElementInterface $to): bool
    {
        foreach (array_filter(ExtractorService::FIELD_EXTRACTORS, 'class_exists') as $extractor) {
            if (!is_subclass_of($extractor, InterfaceFieldReplacer::class)) {
                continue;
            }
            $result = $extractor::replace($field, $element, $from, $to);
            if ($result !== null) {
                return $result;
            }
        }
        return false;
    }

    /** Same for usages stored outside custom fields (user photo, navigation node, …), see InterfaceElementReplacer. */
    public static function replaceInElement(ElementInterface $element, ElementInterface $from, ElementInterface $to): bool
    {
        foreach (array_filter(ExtractorService::ELEMENT_EXTRACTORS, 'class_exists') as $extractor) {
            if (!is_subclass_of($extractor, InterfaceElementReplacer::class)) {
                continue;
            }
            $result = $extractor::replace($element, $from, $to);
            if ($result !== null) {
                return $result;
            }
        }
        return false;
    }
}
