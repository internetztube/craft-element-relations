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

        $whereStatements = collect($customFields)
            ->pluck('uid')
            ->map(function (string $uid) use ($searchValue, $pathForNestedJson) {
                $columnSelector = self::dbJsonExtract("elements_sites.content", [$uid, ...$pathForNestedJson]);
                return ["=", $columnSelector, $searchValue];
            });

        if ($whereStatements->isEmpty()) {
            return null;
        }

        return (new Query())
            ->select("elements_sites.*")
            ->from(["elements_sites" => Table::ELEMENTS_SITES])
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
                    ->map(fn(FieldLayoutTab $fieldLayoutTab) => $fieldLayoutTab->elements)
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
            ->flatten()
            ->values()
            ->all();
    }

    public static function dbJsonExtract(string $column, array $path): string
    {
        $db = Craft::$app->getDb();
        $column = $db->quoteColumnName($column);

        if ($db->getIsMysql()) {
            $path = $db->quoteValue(
                sprintf('$.%s', implode('.', array_map(fn(string $seg) => sprintf('"%s"', $seg), $path)))
            );
            // Maria doesn't support ->/->> operators :(
            if ($db->getIsMaria()) {
                return "JSON_UNQUOTE(JSON_EXTRACT($column, $path))";
            }
            return "($column->>$path)";
        }

        if ($db->getIsPgsql()) {
            $path = $db->quoteValue(
                sprintf('{%s}', implode(',', array_map(fn(string $seg) => sprintf('"%s"', $seg), $path)))
            );

            return "($column#>>$path)";
        }
        return "";
    }
}