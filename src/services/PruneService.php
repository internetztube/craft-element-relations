<?php

declare(strict_types=1);

namespace internetztube\elementRelations\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use yii\db\Expression;

/**
 * Removes cache rows nothing can ever read: their source element, owner or target is gone (Craft's garbage collection
 * hard-deletes trashed elements in SQL, nested ones by cascade, without element events), their owner is a revision,
 * or their source is a Neo block a field converted to Matrix left behind.
 * Every read skips such rows, but they still cost: on a large site they were 98 % of the table.
 *
 * Runs on every garbage collection (ElementRelations::init()), once as a migration, and via
 * `php craft element-relations/prune`.
 */
class PruneService
{
    /** Tables with more rows than this are rebuilt (rows to keep copied out and back), not deleted from. */
    public const REBUILD_MIN_ROWS = 1_000_000;

    private const BATCH = 50_000;

    /**
     * @param (callable(string): void)|null $log progress lines (console, migration)
     * @return int rows removed
     */
    public static function prune(?callable $log = null, ?bool $rebuild = null): int
    {
        $db = Craft::$app->getDb();
        if (!$db->tableExists('{{%elementrelations_cache}}')) {
            return 0;
        }
        $log ??= fn(string $line) => null;
        $rebuild ??= self::estimatedRows() >= self::REBUILD_MIN_ROWS;
        $removed = $rebuild ? self::rebuild($log) : self::deleteInBatches($log);
        \internetztube\elementRelations\elements\db\UsageQuery::resetPermissionCache();
        \internetztube\elementRelations\models\RelationsModel::forgetPrefetched();
        return $removed;
    }

    /**
     * The rows to keep, as a condition on the cache table aliased `c`: plugin settings (no source element), and
     * relations of live or trashed (restorable) elements, never of revisions, to targets that exist.
     * @return array<int|string, mixed>
     */
    private static function keepCondition(): array
    {
        $exists = fn(string $column, array $where = []): array => ['exists', (new Query())
            ->select(new Expression('1'))
            ->from(['e' => Table::ELEMENTS])
            ->where(['e.id' => new Expression("[[c.$column]]")])
            ->andWhere($where)];
        // plugin settings rows stay whatever they point at: their provider writes them from the settings (refresh())
        return ['or',
            ['c.sourceElementId' => null],
            ['and', $exists('targetElementId'), $exists('sourceElementId'), $exists('sourcePrimaryOwnerId', ['e.revisionId' => null]), self::notLeftBehindByNeo()],
        ];
    }

    /**
     * Not a Neo block of a field converted to Matrix, nor an entry nested in one: the conversion keeps the blocks,
     * owned as before, but nothing shows them (ExtractorService::primaryOwner()). As a condition on an element id column
     * (the cache's source here, the elements a full rebuild goes through in GenerateResaveAllElementRelationsJobsJob);
     * NOT EXISTS, which databases run as an anti join, also over a whole table.
     * @return array<int|string, mixed>
     * @internal
     */
    public static function notLeftBehindByNeo(string $column = 'c.sourceElementId'): array
    {
        $leftBehind = fn(Query $query): array => ['not exists', $query
            ->select(new Expression('1'))
            ->innerJoin(['f' => Table::FIELDS], '[[f.id]] = [[b.fieldId]]')
            ->andWhere(['not', ['f.type' => \benf\neo\Field::class]])];
        return Craft::$app->getDb()->tableExists('{{%neoblocks}}') ? ['and',
            $leftBehind((new Query())->from(['b' => '{{%neoblocks}}'])->where(['b.id' => new Expression("[[$column]]")])),
            $leftBehind((new Query())->from(['n' => Table::ENTRIES])->innerJoin(['b' => '{{%neoblocks}}'], '[[b.id]] = [[n.primaryOwnerId]]')->where(['n.id' => new Expression("[[$column]]")])),
        ] : [];
    }

    /** Deletes junk rows id range by id range: no long locks, any database, any size (slow on huge tables). */
    private static function deleteInBatches(callable $log): int
    {
        $db = Craft::$app->getDb();
        /** @var array{min: int|string|null, max: int|string|null} $range an aggregate always has its row (nulls when empty) */
        $range = (new Query())->select(['min' => 'MIN([[id]])', 'max' => 'MAX([[id]])'])->from('{{%elementrelations_cache}}')->one();
        $removed = 0;
        for ($from = (int)($range['min'] ?? 0); $from <= (int)($range['max'] ?? -1); $from += self::BATCH) {
            $ids = (new Query())
                ->select(['c.id'])
                ->from(['c' => '{{%elementrelations_cache}}'])
                ->where(['between', 'c.id', $from, $from + self::BATCH - 1])
                ->andWhere(['not', self::keepCondition()])
                ->column();
            if ($ids) {
                $removed += $db->createCommand()->delete('{{%elementrelations_cache}}', \internetztube\elementRelations\ElementRelations::idIn('id', $ids)->expression)->execute();
            }
        }
        $log("removed $removed rows");
        return $removed;
    }

    /**
     * Large tables: copies the rows to keep into a temporary table, empties the cache table and copies them back. Far
     * faster than deleting most of a table and leaves no bloat; indexes, their names and the id sequence stay. On
     * PostgreSQL in one transaction: writers wait from the start, readers only for the copy back. MySQL's TRUNCATE
     * commits by itself, so there it runs without one (a relation written meanwhile comes back on its next save).
     */
    private static function rebuild(callable $log): int
    {
        $db = Craft::$app->getDb();
        $pgsql = $db->getIsPgsql();
        $table = $db->quoteTableName($db->getSchema()->getRawTableName('{{%elementrelations_cache}}'));
        $keep = $db->quoteTableName($db->getSchema()->getRawTableName('{{%elementrelations_cache}}') . '_keep');
        // an exact count of a huge table would be one more full scan, for a log line
        $before = self::estimatedRows();
        $transaction = $pgsql ? $db->beginTransaction() : null;
        try {
            // PostgreSQL: writers wait (reads go on until the truncate), and room to hash `elements` once per join
            array_map(fn(string $sql): int => $db->createCommand($sql)->execute(), $pgsql ? ["LOCK TABLE $table IN EXCLUSIVE MODE", "SET LOCAL work_mem = '256MB'"] : []);
            // keepCondition() as joins: over the whole table the database hashes `elements` once per join, where the
            // EXISTS form checks every row
            $select = (new Query())
                ->select(['c.*'])
                ->from(['c' => '{{%elementrelations_cache}}'])
                ->leftJoin(['t' => Table::ELEMENTS], '[[t.id]] = [[c.targetElementId]]')
                ->leftJoin(['s' => Table::ELEMENTS], '[[s.id]] = [[c.sourceElementId]]')
                ->leftJoin(['o' => Table::ELEMENTS], '[[o.id]] = [[c.sourcePrimaryOwnerId]]')
                ->where(['or',
                    ['c.sourceElementId' => null],
                    ['and', ['not', ['t.id' => null]], ['not', ['s.id' => null]], ['not', ['o.id' => null]], ['o.revisionId' => null], self::notLeftBehindByNeo()],
                ]);
            [$sql, $params] = $db->getQueryBuilder()->build($select);
            $db->createCommand("DROP TABLE IF EXISTS $keep")->execute();
            $db->createCommand("CREATE TEMPORARY TABLE $keep AS $sql", $params)->execute();
            $kept = (int)(new Query())->from($keep)->count();
            $log(sprintf('keeping %d of about %d rows', $kept, $before));
            $db->createCommand("TRUNCATE TABLE $table")->execute();
            $db->createCommand("INSERT INTO $table SELECT * FROM $keep")->execute();
            $db->createCommand("DROP TABLE $keep")->execute();
            $db->createCommand(($pgsql ? 'ANALYZE ' : 'ANALYZE TABLE ') . $table)->execute();
            $transaction?->commit();
        } catch (\Throwable $e) {
            $transaction?->rollBack();
            throw $e;
        }
        $removed = max(0, $before - $kept);
        $log("removed about $removed rows");
        return $removed;
    }

    /** The database's estimate of the table's rows (no full count of a huge table); a count when it has none. */
    private static function estimatedRows(): int
    {
        $db = Craft::$app->getDb();
        $name = $db->getSchema()->getRawTableName('{{%elementrelations_cache}}');
        $sql = $db->getIsPgsql() ? 'SELECT reltuples::bigint FROM pg_class WHERE oid = to_regclass(:table)' : 'SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table';
        $estimate = (int)$db->createCommand($sql, [':table' => $db->getIsPgsql() ? $db->quoteTableName($name) : $name])->queryScalar();
        return $estimate >= self::REBUILD_MIN_ROWS ? $estimate : (int)(new Query())->from('{{%elementrelations_cache}}')->count();
    }
}
