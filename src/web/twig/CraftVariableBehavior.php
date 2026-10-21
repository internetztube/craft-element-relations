<?php
declare(strict_types=1);

namespace internetztube\elementRelations\web\twig;

use Craft;
use internetztube\elementRelations\elements\db\UsageQuery;
use internetztube\elementRelations\elements\Usage;
use yii\base\Behavior;

/** `craft.usages()` in Twig: a query over everything that uses an element, across all element types (see elements/Usage). */
final class CraftVariableBehavior extends Behavior
{
    /**
     * @param array<string, mixed> $criteria
     */
    public function usages(array $criteria = []): UsageQuery
    {
        $query = Usage::find();
        Craft::configure($query, $criteria);
        return $query;
    }
}
