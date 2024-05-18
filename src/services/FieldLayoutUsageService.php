<?php

namespace internetztube\elementRelations\services;

use Craft;
use craft\base\Field;
use craft\fieldlayoutelements\CustomField;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

class FieldLayoutUsageService
{
    /**
     * Retrieves all field layouts where a specific field type is used. The uid of all Custom Fields with the 
     * respective field type are also extracted. This uid is used as a key in `elements_sites`.`content`.
     *
     * @param string $fieldClass The class name of the field type to search for.
     * @return array[]
     *
     * <code>
     * [
     *   ['fieldLayout' => FieldLayout, 'layoutElements' => CustomField[]]
     * ]
     * </code>
     */
    public function getFieldLayoutWhereFieldTypeIsUsed(string $fieldClass): array
    {
        $fields = Craft::$app->fields->getFieldsByType($fieldClass);
        $fieldsUid = collect($fields)->pluck('uid')->all();

        $fieldLayouts = Craft::$app->fields->getAllLayouts();
        return collect($fieldLayouts)
            ->map(function (FieldLayout $fieldLayout) use ($fieldClass, $fieldsUid) {
                $customFields = collect($fieldLayout->getTabs())
                    ->map(function(FieldLayoutTab $fieldLayoutTab) {
                        return $fieldLayoutTab->elements;
                    })
                    ->flatten(1)
                    ->filter(fn($field) => $field instanceof \craft\fieldlayoutelements\CustomField)
                    ->filter(fn(CustomField $customField) => in_array($customField->fieldUid, $fieldsUid))
                    ->values();
                if ($customFields->isEmpty()) {
                    return null;
                }
                return [
                    'fieldLayout' => $fieldLayout,
                    'layoutElements' => $customFields->all(),
                ];
            })
            ->filter()
            ->all();
    }
}