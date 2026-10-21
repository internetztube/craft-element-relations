<?php
declare(strict_types=1);

namespace internetztube\elementRelations\gql\handlers;

use craft\db\Query;
use craft\db\Table;
use GraphQL\Error\UserError;
use craft\gql\base\ArgumentHandler;

/**
 * The `usesElement` argument of Craft's own GraphQL queries (`entries`, `assets`, `products`, …): the elements that
 * use the given element(s), as the Twig `usesElement` query param lists them. Craft only applies arguments that are
 * methods of the query class, and `usesElement` is a behavior, so the argument is rewritten into `siteSettingsId`
 * (the element-site pairs the cache holds), which Craft's resolver and the schema's own scoping then handle.
 */
final class UsesElement extends ArgumentHandler
{
    /** At most this many usages; more would exceed PostgreSQL's 65,535 bound parameters in Craft's `siteSettingsId`. */
    public static int $maxUsages = 50000;

    protected string $argumentName = 'usesElement';

    /**
     * @param array<string, mixed> $argumentList
     * @return array<string, mixed>
     */
    public function handleArgumentCollection(array $argumentList = []): array
    {
        $pairs = $this->handleArgument($argumentList[$this->argumentName]);
        unset($argumentList[$this->argumentName]);
        // no usage: match nothing (an empty list would mean "any")
        $given = isset($argumentList['siteSettingsId']) ? array_map(fn(mixed $id): int => is_numeric($id) ? (int)$id : 0, (array)$argumentList['siteSettingsId']) : null;
        $argumentList['siteSettingsId'] = ($given === null ? $pairs : array_values(array_intersect($given, $pairs))) ?: [0];
        return $argumentList;
    }

    /**
     * The element-site pairs (`elements_sites.id`) of everything that uses the given element(s).
     * @return list<int>
     */
    protected function handleArgument(mixed $argumentValue): array
    {
        $targetIds = array_map(fn(mixed $id): int => is_numeric($id) ? (int)$id : 0, (array)$argumentValue);
        $pairs = array_map(intval(...), (new Query())
            ->select(['elements_sites.id'])
            ->distinct()
            ->from(['elementrelations_cache' => '{{%elementrelations_cache}}'])
            ->innerJoin(['elements_sites' => Table::ELEMENTS_SITES], '[[elements_sites.elementId]] = [[elementrelations_cache.sourcePrimaryOwnerId]] AND [[elements_sites.siteId]] = [[elementrelations_cache.sourceSiteId]]')
            ->where(['elementrelations_cache.targetElementId' => $targetIds])
            ->andWhere(['not', ['elementrelations_cache.sourceElementId' => null]])
            ->limit(self::$maxUsages + 1)
            ->column());
        if (count($pairs) > self::$maxUsages) {
            throw new UserError(sprintf('`usesElement`: more than %d usages; use the element’s relations field (`count`, `elements` with a limit) instead.', self::$maxUsages));
        }
        return $pairs;
    }
}
