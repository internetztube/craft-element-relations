<?php

namespace internetztube\elementRelations\services\extractors;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use internetztube\elementRelations\records\ElementRelationsCacheRecord;
use verbb\formie\base\ElementField;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;

/**
 * verbb/formie 4. Formie's form fields are not Craft fields and never reach Craft's `relations` table, so:
 * - Form: redirect entry (`redirectEntryId`), redirect rule entries (`settings.redirectRules[].redirectEntry`),
 *   element field defaults (`defaultValue`) and fixed options (`sourceElements`), notification asset attachments
 *   (`attachAssets`). All stored as `[{id: …}]` lists (or a plain id).
 * - Submission: values of Entries/Categories/Users/Products/File Upload/... fields, nested Group/Repeater ones included.
 * Formie's own Craft fields (`verbb\formie\fields\Forms`/`Submissions` on entries) extend BaseRelationField and are
 * handled by the generic field extractors.
 */
class ElementExtractorFormieFormService implements InterfaceElementExtractor
{
    /**
     * @return ElementRelationsCacheRecord[]|false
     */
    public static function getRelations(ElementInterface $element, ElementRelationsCacheRecord $baseRecord): ElementRelationsCacheRecord|array|false
    {
        if (!Craft::$app->getPlugins()->isPluginEnabled('formie')) {
            return false;
        }
        if ($element instanceof Form) {
            $targets = self::formTargets($element);
        } elseif ($element instanceof Submission && !$element->hasLockedContent()) {
            $targets = [];
            foreach ($element->getFieldValuesForField(ElementField::class) as $value) {
                if ($value instanceof ElementQueryInterface) {
                    array_push($targets, ...(clone $value)->status(null)->all());
                }
            }
        } else {
            return false;
        }

        $records = [];
        foreach ($targets as $target) {
            $record = clone $baseRecord;
            $record->setAttributes([
                'type' => ElementRelationsCacheRecord::TYPE_FIELD,
                'targetElementId' => $target->id,
                'targetSiteId' => $target->siteId,
            ]);
            $records["$target->id-$target->siteId"] = $record;
        }
        return array_values($records) ?: false;
    }

    /** @return ElementInterface[] */
    private static function formTargets(Form $form): array
    {
        $targets = self::find(Entry::class, $form->redirectEntryId, $form->redirectEntrySiteId ?: $form->siteId);
        foreach ($form->getSettings()?->redirectRules ?? [] as $rule) {
            $ref = $rule['redirectEntry'] ?? null;
            $targets = [...$targets, ...self::find(Entry::class, $ref, (int)($ref['siteId'] ?? $ref[0]['siteId'] ?? 0) ?: $form->siteId)];
        }
        foreach ($form->getFieldsRecursively() as $field) {
            if ($field instanceof ElementField) {
                $targets = [
                    ...$targets,
                    ...self::find($field::elementType(), $field->defaultValue, $form->siteId),
                    ...self::find($field::elementType(), $field->sourceElements, $form->siteId),
                ];
            }
        }
        foreach ($form->getNotifications() ?? [] as $notification) {
            $targets = [...$targets, ...self::find(Asset::class, $notification->attachAssets, $form->siteId)];
        }
        return $targets;
    }

    /**
     * Existing elements for a Formie id reference: an id, a list of ids, `{id: …}` or a list of `{id: …}`.
     * @param class-string<ElementInterface> $elementType
     * @return ElementInterface[]
     */
    private static function find(string $elementType, mixed $ref, int $siteId): array
    {
        $ref = is_array($ref) && isset($ref['id']) ? [$ref] : (array)$ref;
        $ids = array_filter(array_map(fn($item) => (int)(is_array($item) ? ($item['id'] ?? 0) : $item), $ref));
        if (!$ids) {
            return [];
        }
        return $elementType::find()->id($ids)->status(null)->site('*')->preferSites([$siteId])->unique()->all();
    }
}
