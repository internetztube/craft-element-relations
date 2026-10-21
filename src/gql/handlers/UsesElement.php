<?php
declare(strict_types=1);

namespace internetztube\elementRelations\gql\handlers;

use craft\db\Query;
use craft\db\Table;
use craft\errors\GqlException;
use craft\gql\base\ArgumentHandler;

/**
 * The `usesElement` argument of Craft's own GraphQL queries (`entries`, `assets`, `products`, …): the elements that
 * use the given element(s), as the Twig `usesElement` query param lists them. Craft only applies arguments that are
 * methods of the query class, and `usesElement` is a behavior, so the argument is rewritten into `siteSettingsId`
 * (the element-site pairs the cache holds), which Craft's resolver and the schema's own scoping then handle.
 */
final class UsesElement extends ArgumentHandler
{
    /** Below PostgreSQL's 65,535 bound parameters, which Craft's `siteSettingsId` would exceed. */
    public const MAX_USAGES = 50000;

    protected string $argumentName = 'usesElement';

    /**
     * @param array<string, mixed> $argumentList
     * @return array<string, mixed>
     */
    public function handleArgumentCollection(array $argumentList = []): array
    {
        $targetIds = array_map(fn(mixed $id): int => is_numeric($id) ? (int)$id : 0, (array)$argumentList[$this->argumentName]);
        unset($argumentList[$this->argumentName]);
        $pairs = array_map(intval(...), (new Query())
            ->select(['elements_sites.id'])
            ->distinct()
            ->from(['elementrelations_cache' => '{{%elementrelations_cache}}'])
            ->innerJoin(['elements_sites' => Table::ELEMENTS_SITES], '[[elements_sites.elementId]] = [[elementrelations_cache.sourcePrimaryOwnerId]] AND [[elements_sites.siteId]] = [[elementrelations_cache.sourceSiteId]]')
            ->where(['elementrelations_cache.targetElementId' => $targetIds])
            ->andWhere(['not', ['elementrelations_cache.sourceElementId' => null]])
            ->limit(self::MAX_USAGES + 1)
            ->column());
        if (count($pairs) > self::MAX_USAGES) {
            throw new GqlException(sprintf('`usesElement`: more than %d usages; use the element’s relations field (`count`, `elements` with a limit) instead.', self::MAX_USAGES));
        }
        // no usage: match nothing (an empty list would mean "any")
        $given = isset($argumentList['siteSettingsId']) ? array_map(fn(mixed $id): int => is_numeric($id) ? (int)$id : 0, (array)$argumentList['siteSettingsId']) : null;
        $argumentList['siteSettingsId'] = ($given === null ? $pairs : array_values(array_intersect($given, $pairs))) ?: [0];
        return $argumentList;
    }

    protected function handleArgument(mixed $argumentValue): mixed
    {
        return $argumentValue; // handleArgumentCollection() does the work: it writes another argument
    }
}
