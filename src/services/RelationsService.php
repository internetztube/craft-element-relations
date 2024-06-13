<?php

namespace internetztube\elementRelations\services;

use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use internetztube\elementRelations\services\fields\FieldRelationsService;
use internetztube\elementRelations\services\fields\FieldUserPhotoService;
use internetztube\elementRelations\services\fields\FieldHyperService;
use internetztube\elementRelations\services\fields\FieldLinkItService;
use internetztube\elementRelations\services\fields\FieldSeomaticService;
use internetztube\elementRelations\services\contentbehaviors\ContentBehaviorCommerceService;
use internetztube\elementRelations\services\contentbehaviors\ContentBehaviorMatrixService;
use internetztube\elementRelations\services\contentbehaviors\ContentBehaviorNeoService;

class RelationsService
{
    /**
     * @param ElementInterface $element
     * @return ElementInterface[]
     */
    public static function getReverseRelations(ElementInterface $element): array
    {
        $elementsSitesUnionQuery = FieldRelationsService::getElementsSitesQuery($element);

        if ($hyper = FieldHyperService::getElementsSitesQuery($element)) {
            $elementsSitesUnionQuery->union($hyper);
        }

        if ($linkIt = FieldLinkItService::getElementsSitesQuery($element)) {
            $elementsSitesUnionQuery->union($linkIt);
        }

        if ($seomatic = FieldSeomaticService::getElementsSitesQuery($element)) {
            $elementsSitesUnionQuery->union($seomatic);
        }

        if ($userPhoto = FieldUserPhotoService::getElementsSitesQuery($element)) {
            $elementsSitesUnionQuery->union($userPhoto);
        }

        return self::getElementsByBaseElementInfo(
            self::getReverseRelationsForElementsSitesQuery($elementsSitesUnionQuery)
        );
    }

    /**
     * @param Query $elementsSitesQuery
     * @return array BaseElementInfo
     */
    private static function getReverseRelationsForElementsSitesQuery(Query $elementsSitesQuery): array
    {
        $tableElementsSites = Table::ELEMENTS_SITES;
        $tableElements = Table::ELEMENTS;
        $query = (new Query())
            ->select([
                "$tableElementsSites.elementId",
                "$tableElementsSites.siteId",
                "$tableElements.type",
                ...ContentBehaviorMatrixService::getColumns(),
                ...ContentBehaviorNeoService::getColumns(),
                ...ContentBehaviorCommerceService::getColumns(),
            ])
            ->from([$tableElementsSites => $elementsSitesQuery])
            ->innerJoin($tableElements, "$tableElementsSites.elementId = $tableElements.id");

        $query = ContentBehaviorMatrixService::enrichQuery($query);
        $query = ContentBehaviorNeoService::enrichQuery($query);
        $query = ContentBehaviorCommerceService::enrichQuery($query);

        return $query->collect()
            ->map(fn(array $row) => [
                "elementId" =>
                    $row[ContentBehaviorMatrixService::getColumnElementsId()] ??
                    $row[ContentBehaviorNeoService::getColumnElementsId()] ??
                    $row[ContentBehaviorCommerceService::getColumnElementsId()] ??
                    $row["elementId"],
                "type" =>
                    $row[ContentBehaviorMatrixService::getColumnElementsType()] ??
                    $row[ContentBehaviorNeoService::getColumnElementsType()] ??
                    $row[ContentBehaviorCommerceService::getColumnElementsType()] ??
                    $row["type"],
                "siteId" => $row["siteId"],
            ])
            ->all();
    }

    private static function getElementsByBaseElementInfo(array $baseElementInfo): array
    {
        return collect($baseElementInfo)
            ->groupBy("type")
            ->map(function ($values, $elementType) {
                $whereStatements = collect($values)
                    ->map(fn($value) => [
                        "elements.id" => $value["elementId"],
                        "elements_sites.siteId" => $value["siteId"]
                    ]);

                /** @var ElementQuery $query */
                $query = $elementType::find()->where(["or", ...$whereStatements]);

                return [
                    ...$query->status(null)->all(),
                    ...$query->status(null)->drafts()->all(),
                ];
            })
            ->flatten()
            ->filter()
            ->map(function (ElementInterface $element) {
                /**
                 * Simple relations based on primaryOwnerId get resolved in the base queries.
                 * However, if someone is, for example, nesting Matrix and NEO, the primaryOwnerId just points to the
                 * outermost Matrix/NEO Block, but not to the real Primary Owner.
                 * Please don't mix Matrix and NEO for best performance results!
                 */
                while (
                    method_exists($element, "getPrimaryOwnerId") &&
                    method_exists($element, "getPrimaryOwner") &&
                    $element->getPrimaryOwnerId()
                ) {
                    $element = $element->getPrimaryOwner();
                }
                return $element;
            })
            ->toArray();
    }
}