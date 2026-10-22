<?php

declare(strict_types=1);

namespace internetztube\elementRelations\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use yii\db\Expression;

/**
 * Removes cache rows nothing can ever read: their source element, owner or target is gone (Craft's garbage collection
 * hard-deletes trashed elements in SQL, nested ones by cascade, without element events), or their owner is a revision.
 * Every read skips such rows, but they still cost: on a large site they were 98 % of the table.
 *
 * Runs on every garbage collection (ElementRelations::init()), once as a migration, and via
 * `php craft element-relations/prune`.
 */
class PruneService
{
    /** Tables larger than this are rebuilt (live rows copied, tables swapped) on PostgreSQL, not deleted from. */
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
        $rebuild ??= $db->getIsPgsql() && self::estimatedRows() >= self::REBUILD_MIN_ROWS;
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
            ['and', $exists('targetElementId'), $exists('sourceElementId'), $exists('sourcePrimaryOwnerId', ['e.revisionId' => null])],
        ];
    }

    /** Deletes junk rows id range by id range: no long locks, any database, any size (slow on huge tables). */
    private static function deleteInBatches(callable $log): int
    {
        $db = Craft::$app->getDb();
        $range = (new Query())->select(['min' => 'MIN([[id]])', 'max' => 'MAX([[id]])'])->from('{{%elementrelations_cache}}')->one();
        if (!$range || $range['max'] === null) {
            return 0;
        }
        $removed = 0;
        for ($from = (int)$range['min']; $from <= (int)$range['max']; $from += self::BATCH) {
            $ids = (new Query())
                ->select(['c.id'])
                ->from(['c' => '{{%elementrelations_cache}}'])
                ->where(['between', 'c.id', $from, $from + self::BATCH - 1])
                ->andWhere(['not', self::keepCondition()])
                ->column();
            if ($ids) {
                $removed += $db->createCommand()->delete('{{%elementrelations_cache}}', \internetztube\elementRelations\ElementRelations::idIn('id', $ids))->execute();
            }
        }
        $log("removed $removed rows");
        return $removed;
    }

    /**
     * PostgreSQL, large tables: copies the rows to keep into a new table, builds its indexes and swaps it in. Far
     * faster than deleting most of a table, and leaves no bloat. Writers wait for the copy (reads go on); PostgreSQL
     * resolves their table again after the swap.
     */
    private static function rebuild(callable $log): int
    {
        $db = Craft::$app->getDb();
        $table = $db->getSchema()->getRawTableName('{{%elementrelations_cache}}');
        $new = $table . '_pruned';
        $quoted = $db->quoteTableName($table);
        $quotedNew = $db->quoteTableName($new);
        // an exact count of a huge table would be one more full scan, for a log line
        $before = self::estimatedRows();
        if ($before < self::REBUILD_MIN_ROWS) {
            $before = (int)(new Query())->from('{{%elementrelations_cache}}')->count();
        }
        $transaction = $db->beginTransaction();
        try {
            $db->createCommand("LOCK TABLE $quoted IN EXCLUSIVE MODE")->execute();
            // room to hash `elements` once per join below, for this transaction only
            $db->createCommand("SET LOCAL work_mem = '256MB'")->execute();
            $indexes = (new Query())
                ->select(['indexname', 'indexdef'])
                ->from('pg_indexes')
                ->where(['schemaname' => new Expression('current_schema()'), 'tablename' => $table])
                ->all();
            $sequence = $db->createCommand("SELECT pg_get_serial_sequence(:table, 'id')", [':table' => $quoted])->queryScalar();
            $db->createCommand("DROP TABLE IF EXISTS $quotedNew")->execute();
            $db->createCommand("CREATE TABLE $quotedNew (LIKE $quoted INCLUDING DEFAULTS)")->execute();
            // keepCondition() as joins: over the whole table PostgreSQL hashes `elements` once per join, where the
            // EXISTS form checks every row
            $select = (new Query())
                ->select(['c.*'])
                ->from(['c' => '{{%elementrelations_cache}}'])
                ->leftJoin(['t' => Table::ELEMENTS], '[[t.id]] = [[c.targetElementId]]')
                ->leftJoin(['s' => Table::ELEMENTS], '[[s.id]] = [[c.sourceElementId]]')
                ->leftJoin(['o' => Table::ELEMENTS], '[[o.id]] = [[c.sourcePrimaryOwnerId]]')
                ->where(['or',
                    ['c.sourceElementId' => null],
                    ['and', ['not', ['t.id' => null]], ['not', ['s.id' => null]], ['not', ['o.id' => null]], ['o.revisionId' => null]],
                ]);
            [$sql, $params] = $db->getQueryBuilder()->build($select);
            $kept = $db->createCommand("INSERT INTO $quotedNew $sql", $params)->execute();
            $log(sprintf('kept %d of %d rows', $kept, $before));
            // the indexes, built on the small table; named after the old ones once those are gone
            foreach ($indexes as $index) {
                // pg_indexes: CREATE [UNIQUE] INDEX name ON [schema.]table USING ...
                $def = preg_replace('/ ON (?:\S+\.)?"?' . preg_quote($table, '/') . '"? USING /', " ON $quotedNew USING ", (string)$index['indexdef'], 1, $count);
                if ($count !== 1) {
                    throw new \RuntimeException("Unexpected index definition: {$index['indexdef']}");
                }
                $def = str_replace('INDEX ' . $index['indexname'] . ' ', 'INDEX ' . $index['indexname'] . '_p ', (string)$def);
                $db->createCommand((string)$def)->execute();
            }
            if ($sequence) {
                $db->createCommand("ALTER SEQUENCE $sequence OWNED BY $quotedNew.[[id]]")->execute();
            }
            $db->createCommand("DROP TABLE $quoted")->execute();
            $db->createCommand("ALTER TABLE $quotedNew RENAME TO " . $db->getSchema()->quoteSimpleTableName($table))->execute();
            foreach ($indexes as $index) {
                $db->createCommand('ALTER INDEX ' . $db->getSchema()->quoteSimpleTableName($index['indexname'] . '_p') . ' RENAME TO ' . $db->getSchema()->quoteSimpleTableName((string)$index['indexname']))->execute();
            }
            $pk = array_filter($indexes, fn(array $index): bool => str_ends_with((string)$index['indexname'], '_pkey'));
            if ($pk) {
                $db->createCommand('ALTER TABLE ' . $quoted . ' ADD CONSTRAINT ' . $db->getSchema()->quoteSimpleTableName((string)reset($pk)['indexname']) . ' PRIMARY KEY USING INDEX ' . $db->getSchema()->quoteSimpleTableName((string)reset($pk)['indexname']))->execute();
            }
            $db->createCommand("ANALYZE $quoted")->execute();
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        $db->getSchema()->refreshTableSchema('{{%elementrelations_cache}}');
        return $before - $kept;
    }

    /** PostgreSQL's estimate of the table's rows (no full count on a huge table), else a count. */
    private static function estimatedRows(): int
    {
        $db = Craft::$app->getDb();
        if ($db->getIsPgsql()) {
            $estimate = $db->createCommand('SELECT reltuples::bigint FROM pg_class WHERE oid = to_regclass(:table)', [':table' => $db->quoteTableName($db->getSchema()->getRawTableName('{{%elementrelations_cache}}'))])->queryScalar();
            if ($estimate !== false && (int)$estimate > 0) {
                return (int)$estimate;
            }
        }
        return (int)(new Query())->from('{{%elementrelations_cache}}')->count();
    }
}
