<?php

namespace internetztube\elementRelations\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\fieldlayoutelements\CustomField;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

class FieldLayoutUsageService
{
    public static function getElementsSitesSearchQueryByFieldClass(string $fieldClass, $searchValue, array $pathForNestedJson = []): ?Query
    {
        $customFields = self::getCustomFieldsByFieldClass($fieldClass);
        $queryBuilder = Craft::$app->getDb()->getQueryBuilder();
        $tableElementsSites = Table::ELEMENTS_SITES;

        $whereStatements = collect($customFields)
            ->flatten()
            ->pluck('uid')
            ->map(function (string $uid) use ($queryBuilder, $searchValue, $pathForNestedJson, $tableElementsSites) {
                $columnSelector = $queryBuilder->jsonExtract(
                    "$tableElementsSites.content",
                    [$uid, ...$pathForNestedJson]
                );
                return ["=", $columnSelector, $searchValue];
            });

        if ($whereStatements->isEmpty()) {
            return null;
        }

        return (new Query())
            ->select("$tableElementsSites.*")
            ->from($tableElementsSites)
            ->where(["or", ...$whereStatements]);
    }

    /**
     * Retrieves all field layouts where a specific field type is used. The uid of all Custom Fields with the 
     * respective field type are extracted. This uid is used as a key in `elements_sites`.`content`.
     *
     * @param string $fieldClass The class name of the field type to search for.
     * @return CustomField[]
     */
    private static function getCustomFieldsByFieldClass(string $fieldClass): array
    {

        $fields = Craft::$app->fields->getFieldsByType($fieldClass);
        $fieldsUid = collect($fields)->pluck('uid')->all();

        $fieldLayouts = Craft::$app->fields->getAllLayouts();
        return collect($fieldLayouts)
            ->map(function (FieldLayout $fieldLayout) use ($fieldClass, $fieldsUid) {
                $customFields = collect($fieldLayout->getTabs())
                    ->map(fn (FieldLayoutTab $fieldLayoutTab) => $fieldLayoutTab->elements)
                    ->flatten(1)
                    ->filter(fn($field) => $field instanceof CustomField)
                    ->filter(fn(CustomField $customField) => in_array($customField->fieldUid, $fieldsUid))
                    ->values();
                if ($customFields->isEmpty()) {
                    return null;
                }
                return $customFields->all();
            })
            ->filter()
            ->values()
            ->all();
    }
}