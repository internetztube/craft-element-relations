<?php

namespace internetztube\elementRelations\services;

use craft\base\Element;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQueryInterface;
use craft\fieldlayoutelements\CustomField;
use Illuminate\Support\Collection;
use internetztube\elementRelations\fields\ElementRelationsField;
use internetztube\elementRelations\records\ElementRelationsRecord;
use nystudio107\seomatic\fields\SeoSettings;
use nystudio107\seomatic\records\MetaBundle as SeomaticMetaBundleRecord;

class SeomaticLocalRelationsService
{
    public const RELATION_TYPE = 'seomatic-local';

    /**
     * @param string $input
     * @return false|int[]
     */
    public static function extractElementIdSiteIdFromString(string $input)
    {
        if (!$input) return false;
        $input = trim(trim($input, '{'));
        $input = str_replace(['seomatic.helper.socialTransform(', ')', ' '], '', $input);
        $input = explode(',', $input);
        if (!is_numeric($input[0]) || !is_numeric($input[2])) return false;
        return ['elementId' => (int)$input[0], 'siteId' => (int)$input[2]];
    }

    public function updateRelations()
    {
        ElementRelationsRecord::deleteAll([
            'type' => self::RELATION_TYPE,
        ]);

        $fieldLayoutUsageService = new FieldLayoutUsageService();
        $involvedFieldLayouts = $fieldLayoutUsageService->getFieldLayoutWhereFieldTypeIsUsed(ElementRelationsField::class);

        collect($involvedFieldLayouts)
            ->map(function ($involvedFieldLayout) {
                $layoutElements = $involvedFieldLayout['layoutElements'];
                $fieldHandles = collect($layoutElements)
                    ->map(fn (CustomField $field) => $field->handle ?? $field->getOriginalHandle());
                return (new Query())->select(['elements.type', 'elements_sites.elementId', 'elements_sites.siteId'])
                    ->from(['elements' => Table::ELEMENTS])
                    ->innerJoin(['elements_sites' => Table::ELEMENTS_SITES], '[[elements.id]] = [[elements_sites.id]]')
                    ->where(['elements.fieldLayoutId' => $involvedFieldLayout['fieldLayout']->id])
                    ->collect()
                    ->groupBy('type')
                    ->filter(function (Collection $value, string $elementType) {
                        // craft\models\EntryDraft bug
                        return is_subclass_of($elementType, ElementInterface::class);
                    })
                    ->map(function (Collection $values, string $elementType) {
                        return collect($values)
                            ->map(function ($value) use ($elementType) {
                                /** @var ElementQueryInterface $query */
                                $query = $elementType::find();
                                // can be null when deleted, ...
                                return $query->id($value['elementId'])
                                    ->siteId($value['siteId'])
                                    ->status(null)
                                    ->one();
                            });
                    })
                    ->flatten()
                    ->filter()
                    ->values()
                    ->dd();
            })
            ->filter();

        die();

        $elements = (new Query())->select(['elements.type', 'elements_sites.elementId', 'elements_sites.siteId'])
            ->from(['elements' => Table::ELEMENTS])
            ->innerJoin(['elements_sites' => Table::ELEMENTS_SITES], '[[elements.id]] = [[elements_sites.id]]')
            ->where(['elements.fieldLayoutId' => $fieldLayoutIds])
            ->collect()
            ->groupBy('type')
            ->filter(function (Collection $value, string $elementType) {
                // craft\models\EntryDraft bug
                return is_subclass_of($elementType, ElementInterface::class);
            })
            ->map(function (Collection $values, string $elementType) {
                return collect($values)
                    ->map(function ($value) use ($elementType) {
                        /** @var ElementQueryInterface $query */
                        $query = $elementType::find();
                        return $query->id($value['elementId'])
                            ->siteId($value['siteId'])
                            ->one();
                    });
            });

        dd($elements);
        die();

        dd($elements);

        $elements = Element::find()
            ->select([''])
            ->where(['in', 'fieldLayoutId', $fieldLayoutIds])
            ->site('*')
            ->all();
        dd($elements);

        $records = SeomaticMetaBundleRecord::find()
            ->select(['sourceSiteId', 'metaGlobalVars', 'metaSiteVars'])
            ->all();

        collect($records)->map(function (SeomaticMetaBundleRecord $record) {
            $siteId = $record->sourceSiteId;

            return collect([$record['metaGlobalVars'], $record['metaSiteVars']])
                ->map(function ($row) {
                    return json_decode($row, true);
                })
                ->map(function ($row) use ($siteId) {
                    $result = collect();

                    if (isset($row['seoImage'])) {
                        $resultExtraction = self::extractElementIdSiteIdFromString($row['seoImage']);
                        if (!$resultExtraction) {
                            return;
                        }
                        $result->push($resultExtraction);
                    }

                    if (isset($row['identity']['genericImageIds'])) {
                        collect($row['identity']['genericImageIds'])
                            ->each(function ($elementId) use ($siteId, $result) {
                                if (!$elementId) return;
                                $result->push(['elementId' => (int)$elementId, 'siteId' => (int)$siteId]);
                            });
                    }

                    if (isset($row['creator']['genericImageIds'])) {
                        collect($row['creator']['genericImageIds'])
                            ->each(function ($elementId) use ($siteId, $result) {
                                if (!$elementId) return;
                                $result->push(['elementId' => (int)$elementId, 'siteId' => (int)$siteId]);
                            });
                    }

                    return $result;
                })
                ->flatten(1)
                ->filter()
                ->all();
        })
            ->filter()
            ->flatten(1)
            ->unique(function ($item) {
                return $item['elementId'] . '-' . $item['siteId'];
            })
            ->each(function (array $item) {
                $record = new ElementRelationsRecord([
                    'type' => self::RELATION_TYPE,
                    'targetElementId' => $item['elementId'],
                    'targetSiteId' => $item['siteId'],
                ]);
                $record->save();
            });
    }

}