<?php

namespace internetztube\elementRelations\services;

use Craft;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\db\Table;
use craft\fields\Matrix as MatrixField;
use craft\redactor\Field;
use craft\redactor\FieldData;
use verbb\supertable\fields\SuperTableField;

class RedactorService
{
    /**
     * In which element is a certain other element in it's redactor content.
     * For example, if you want to know in which Entries an Asset is used, then you this method is for you.
     * @param int $elementId
     * @return array
     *
     * <code>
     * [
     *   ['elementId' => int, 'siteId' => int]
     * ]
     * </code>
     */
    public static function getRedactorRelations(int $elementId): array
    {
        $preparedElementId = sprintf('%%:%s:%%', $elementId);
        $mainQuery = (new Query())
            ->from(['elements' => Table::ELEMENTS])
            ->select(['elements.id', 'elements.type']);

        // content table
        $redactorFields = (new Query())->select(['id'])
            ->from(Table::FIELDS)
            ->where(['type' => Field::class])
            ->andWhere(['context' => 'global'])
            ->column();
        $redactorFieldHandles = collect($redactorFields)->map(function (int $fieldId) use ($preparedElementId, $mainQuery) {
            $field = Craft::$app->getFields()->getFieldById($fieldId);
            $fieldHandle = 'content.field_' . $field->columnPrefix . $field->handle;
            if ($field->columnSuffix) {
                $fieldHandle .= '_' . $field->columnSuffix;
            }
            $mainQuery->addSelect($fieldHandle);
            $mainQuery->orWhere(['LIKE', $fieldHandle, $preparedElementId, false]);
            return $fieldHandle;
        });
        if ($redactorFieldHandles->isNotEmpty()) {
            $mainQuery->leftJoin(['content' => Table::CONTENT], '[[content.elementId]] = [[elements.id]]');
            $mainQuery->addSelect('content.siteId as content__siteId');
        }

        $fieldsWithExternalContentTables = collect();
        $matrixFields = (new Query())->select(['id'])->from(Table::FIELDS)->where(['type' => MatrixField::class])->column();
        $fieldsWithExternalContentTables = $fieldsWithExternalContentTables->merge($matrixFields);
        if (self::isSuperTableEnabled()) {
            $superTableFields = (new Query())->select(['id'])->from(Table::FIELDS)->where(['type' => SuperTableField::class])->column();
            $fieldsWithExternalContentTables = $fieldsWithExternalContentTables->merge($superTableFields);
        }

        $fieldsWithExternalContentTables->each(function (int $fieldId, int $index) use ($mainQuery, $preparedElementId) {
            $alias = sprintf('alias_%s', $index);
            /** @var MatrixField|SuperTableField $field */
            $field = Craft::$app->getFields()->getFieldById($fieldId);
            $redactorFields = collect($field->getBlockTypeFields())->filter(function (FieldInterface $field) {
                return $field instanceof Field;
            });
            if ($redactorFields->isEmpty()) {
                return;
            }
            $redactorFields->each(function (Field $field) use ($alias, $mainQuery, $preparedElementId) {
                $fieldHandle = $alias . '.' . $field->columnPrefix . $field->handle;
                if ($field->columnSuffix) {
                    $fieldHandle = $alias . '.' . $field->columnPrefix . $field->handle . '_' . $field->columnSuffix;
                }
                $mainQuery->addSelect([$fieldHandle, $alias . '.siteId as ' . $alias . '__siteId']);
                $mainQuery->orWhere(['LIKE', $fieldHandle, $preparedElementId, false]);
            });
            $aliasFieldName = sprintf('%s.elementId', $alias);
            $mainQuery->leftJoin([$alias => $field->contentTable], '[[' . $aliasFieldName . ']] = [[elements.id]]');
        });

        return collect($mainQuery->all())->map(function (array $row) {
            $siteIdKey = collect($row)->keys()->filter(function (string $fieldHandle) {
                return strstr($fieldHandle, '__siteId');
            })->first();
            $siteId = $row[$siteIdKey];
            $element = ElementRelationsService::getElementById($row['id'], $row[$siteIdKey]);
            if (!$element) {
                return null;
            }
            $rootElement = ElementRelationsService::getRootElement($element, $siteId);
            if (!$rootElement) {
                return null;
            }
            return ['elementId' => $rootElement->id, 'siteId' => $rootElement->siteId];
        })->filter()->all();
    }

    /**
     * Is the SuperTable Plugin installed and enabled?
     * @return bool
     */
    private static function isSuperTableEnabled(): bool
    {
        return Craft::$app->plugins->isPluginEnabled('super-table');
    }

    /**
     * Which elements are used in a certain element.
     * For example, if you want to know which Assets are used in an Entry, then you this method is for you.
     * @param int $elementId
     * @return int[]
     */
    public static function getRedactorRelationsUsedInElement(int $elementId): array
    {
        $element = ElementRelationsService::getElementById($elementId);
        if (!$element) {
            return [];
        }
        return collect($element->getFieldValues())->filter(function ($value) {
            return $value instanceof FieldData;
        })->map(function (FieldData $value) {
            $exploded = explode(':', $value->getRawContent());
            return collect($exploded)->filter(function ($item) {
                return is_numeric($item) && (string)((int)$item) === (string)$item;
            })->map(function ($item) {
                return (int)$item;
            })->values();
        })->flatten()->all();
    }

    /**
     * Is the Redactor Plugin installed and enabled?
     * @return bool
     */
    public static function isRedactorEnabled(): bool
    {
        return Craft::$app->plugins->isPluginEnabled('redactor');
    }
}