<?php

namespace internetztube\elementRelations\services;

use craft\base\Component;
use craft\base\Element;
use craft\db\Query;
use craft\db\Table;
use craft\models\Site;
use internetztube\elementRelations\models\ElementRelationsModel;
use internetztube\elementRelations\records\ElementRelationsRecord;

class ElementRelationsService extends Component
{

    /**
     * @param Element $sourceElement
     * @param int $siteId
     * @return false|ElementRelationsModel
     */
    public static function getStoredRelations(int $elementId, int $siteId) {
        $elementRelationsRecord = ElementRelationsRecord::find()
            ->where(['elementId'=>$elementId])
            ->andWhere(['siteId' => $siteId])
            ->one();

        $elementRelationsModel = new ElementRelationsModel();
        if ($elementRelationsRecord) {
            $attributes = $elementRelationsRecord->getAttributes();
            $elementRelationsModel->setAttributes($attributes, false);
            return $elementRelationsModel;
        }
        return false;
    }


    /**
     * @param int $elementId
     * @param int $siteId
     * @param string $resultHtml
     * @param ElementRelationsRecord $elementRelationsRecord
     */
    public static function setStoredRelations(int $elementId, int $siteId, string $resultHtml,
                                              ElementRelationsRecord $elementRelationsRecord = null) {
//        if (empty($elementRelationsRecord)) {
//            $elementRelationsRecord = new ElementRelationsRecord();
//        }
        $elementRelationsRecord = new ElementRelationsRecord();
        $elementRelationsRecord->setAttribute('elementId', $elementId);
        $elementRelationsRecord->setAttribute('siteId', $siteId);
        $elementRelationsRecord->setAttribute('resultHtml', $resultHtml);
        $elementRelationsRecord->save();
    }

    /**
     * @param Element $sourceElement
     * @param bool $anySite
     * @return array
     */
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

    /**
     * @param int $elementId
     * @param $site
     * @return Element|null
     */
    private static function getElementById (int $elementId, $site): ?Element
    {
        if (is_numeric($site)) {
            $site = \Craft::$app->sites->getSiteById($site);
        }
        $result = (new Query())->select(['type'])->from(Table::ELEMENTS)->where(['id' => $elementId])->one();
        if (!$result) { return null; } // relation is broken
        return $result['type']::find()->id($elementId)->anyStatus()->site($site)->one();
    }

    public static function assetUsageInProfilePhotos(Element $sourceElement)
    {
        $users = (new Query())
            ->select(['id'])
            ->from(Table::USERS)
            ->where(['photoId' => $sourceElement->id])
            ->all();

        return collect($users)->map(function (array $user) {
            return \Craft::$app->users->getUserById($user['id']);
        })->all();
    }

    /**
     * @param Element $sourceElement
     * @return array|false
     */
    public static function assetUsageInSEOmatic(Element $sourceElement)
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

        $fields = (new Query)->select(['handle', 'columnSuffix'])
            ->from(Table::FIELDS)
            ->where(['=', 'type', 'nystudio107\seomatic\fields\SeoSettings'])
            ->all();
        $fields = collect($fields)->map(function ($field) {
            if (empty($field['columnSuffix'])) { return $field['handle']; }
            return sprintf('%s_%s', $field['handle'], $field['columnSuffix']);
        })->toArray();

        $foundElements = collect();

        collect($fields)->each(function ($handle) use (&$foundElements, $extractIdFromString, $sourceElement) {
            $fieldHandle = sprintf('field_%s', $handle);
            $rows = (new Query)->select(['elements.canonicalId', 'elements.id', 'siteId', 'title', 'content.'.$fieldHandle])
                ->from(['content' => Table::CONTENT])
                ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[content.elementId]]')
                ->where(['NOT', ['content.'.$fieldHandle => null]])
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

    /**
     * @param Element $element
     * @param $site
     * @return Element|null
     */
    private static function getRootElement (Element $element, $site): ?Element
    {
        if (!isset($element->ownerId) || !$element->ownerId) { return $element; }
        $sourceElement = self::getElementById($element->ownerId, $site);
        if (!$sourceElement) { return null; }
        return self::getRootElement($sourceElement, $site);
    }
}
