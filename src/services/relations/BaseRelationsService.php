<?php

namespace internetztube\elementRelations\services\relations;

use benf\neo\gql\types\elements\Block;
use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;

class BaseRelationsService
{
    public static function getElementsByBaseElementInfo(array $baseElementInfo): array
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
                    ...$query->all(),
                    ...$query->drafts()->all()
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
                while (method_exists($element, "getPrimaryOwnerId") && $element->getPrimaryOwnerId()) {
                    $element = $element->getPrimaryOwner();
                }
                return $element;
            })
            ->toArray();
    }
}