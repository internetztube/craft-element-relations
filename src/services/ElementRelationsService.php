<?php

namespace internetztube\elementRelations\services;

use craft\base\Component;
use craft\base\Element;
use craft\db\Query;
use craft\db\Table;

class ElementRelationsService extends Component
{
    public static function getRelationsFromElement(Element $sourceElement, bool $anySite = false)
    {
        $elements = (new Query())->select(['elements.id'])
            ->from(['relations' => Table::RELATIONS])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[relations.sourceId]] = [[elements.id]]')
            ->where(['relations.targetId' => $sourceElement->canonicalId])
            ->column();

        $site = $anySite ? '*' : $sourceElement->site;

        return collect($elements)->map(function(int $elementId) use ($site) {
            /** @var ?Element $relation */
            $relation = self::getElementById($elementId, $site);
            if (!$relation) { return null; }
            return self::getRootElement($relation, $site);
        })->filter()->values()->toArray();
    }

    private static function getElementById (int $elementId, $site): ?Element
    {
        if (is_numeric($site)) {
            $site = \Craft::$app->sites->getSiteById($site);
        }
        $result = (new Query())->select(['type'])->from(Table::ELEMENTS)->where(['id' => $elementId])->one();
        if (!$result) { return null; } // relation is broken
        return $result['type']::find()->id($elementId)->anyStatus()->site($site)->one();
    }

    public static function isUsedInSEOmatic(Element $sourceElement)
    {
        $result = ['usedGlobally' => false, 'elements' => []];
        $isInstalled = \Craft::$app->db->tableExists('{{%seomatic_metabundles}}');
        if (!$isInstalled) { return false; }

        $extractIdFromString = function ($input) {
            if (!$input) { return; }
            $result = sscanf($input, '{seomatic.helper.socialTransform(%d, ');
            return (int) collect($result)->first();
        };

        $globalQueryResult = (new Query)->select(['metaGlobalVars', 'metaSiteVars'])
            ->from('{{%seomatic_metabundles}}')
            ->all();

        $result['usedGlobally'] = collect($globalQueryResult)
            ->map(function($row) { return collect($row)->values(); })
            ->flatten()
            ->map(function($row) { return json_decode($row, true); })
            ->map(function ($row) use ($extractIdFromString) {
                if (isset($row['seoImage'])) { return $extractIdFromString($row['seoImage']); }
                if (isset($row['identity']['genericImageIds'])) { return $row['identity']['genericImageIds']; }
                return null;
            })
            ->flatten()->filter()
            ->map(function($row) { return (int) $row; })->unique()
            ->contains($sourceElement->id);

        $fields = (new Query)->select(['handle'])
            ->from(Table::FIELDS)
            ->where(['=', 'type', 'nystudio107\seomatic\fields\SeoSettings'])
            ->column();

        $foundElements = collect();

        collect($fields)->each(function ($handle) use (&$foundElements, $extractIdFromString, $sourceElement) {
            $fieldHandle = sprintf('field_%s', $handle);
            $rows = (new Query)->select(['{{%elements}}.canonicalId', '{{%elements}}.id', 'siteId', 'title', '{{%content}}.'.$fieldHandle])
                ->from(Table::CONTENT)
                ->innerJoin(Table::ELEMENTS, '{{%elements}}.id = {{%content}}.elementId')
                ->where(['NOT', ['{{%content}}.'.$fieldHandle => null]])
                ->all();
            collect($rows)->each(function ($row) use (&$foundElements, $extractIdFromString, $fieldHandle, $sourceElement) {
                $data = json_decode($row[$fieldHandle]);
                $id = $extractIdFromString($data->metaGlobalVars->seoImage);
                if ($id !== $sourceElement->id) { return; }
                $foundElements->push(self::getElementById($row['canonicalId'] ?? $row['id'], $row['siteId']));
            });
        });
        $result['elements'] = collect($foundElements)
            ->unique('canonialId')
            ->toArray();
        return $result;
    }
    private static function getRootElement (Element $element, $site): ?Element
    {
        if (!isset($element->ownerId) || !$element->ownerId) { return $element; }
        $sourceElement = self::getElementById($element->ownerId, $site);
        if (!$sourceElement) { return null; }
        return self::getRootElement($sourceElement, $site);
    }
}