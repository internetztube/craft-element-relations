<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Services;

use CraftCms\Cms\Database\Table;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Internetztube\ElementRelations\Elements\Queries\UsageQuery;
use Internetztube\ElementRelations\Models\CacheRecord;
use Internetztube\ElementRelations\Models\RelationsModel;

/**
 * Removes cache rows nothing can ever read: their source element, owner or target is gone (Craft's garbage collection
 * hard-deletes trashed elements in SQL, nested ones by cascade, without element events), or their owner is a revision.
 * Every read skips such rows, but they still cost: on a large site they were 98 % of the table.
 *
 * Runs on every garbage collection (ElementRelations::boot()) and via `php artisan element-relations:prune`.
 */
class PruneService
{
    /** Tables with more rows than this are rebuilt (rows to keep copied out and back), not deleted from. */
    public const REBUILD_MIN_ROWS = 1_000_000;

    private const BATCH = 50_000;

    /**
     * @param (callable(string): void)|null $log progress lines (console)
     * @return int rows removed
     */
    public static function prune(?callable $log = null, ?bool $rebuild = null): int
    {
        if (!Schema::hasTable(CacheRecord::TABLE)) {
            return 0;
        }
        $log ??= fn(string $line) => null;
        $rebuild ??= self::estimatedRows() >= self::REBUILD_MIN_ROWS;
        $removed = $rebuild ? self::rebuild($log) : self::deleteInBatches($log);
        UsageQuery::resetPermissionCache();
        RelationsModel::forgetPrefetched();
        return $removed;
    }

    /**
     * The rows to keep: plugin settings (no source element), and relations of live or trashed (restorable) elements,
     * never of revisions, to targets that exist.
     */
    private static function keep(Builder $query): void
    {
        $cache = CacheRecord::TABLE;
        $exists = fn(string $column, bool $notRevision = false): \Closure => fn(Builder $sub) => $sub
            ->selectRaw('1')
            ->from(Table::ELEMENTS . ' as e')
            ->whereColumn('e.id', "$cache.$column")
            ->when($notRevision, fn(Builder $owner) => $owner->whereNull('e.revisionId'));
        // plugin settings rows stay whatever they point at: their provider writes them from the settings (refresh())
        $query->whereNull("$cache.sourceElementId")
            ->orWhere(fn(Builder $live) => $live
                ->whereExists($exists('targetElementId'))
                ->whereExists($exists('sourceElementId'))
                ->whereExists($exists('sourcePrimaryOwnerId', true)));
    }

    /** Deletes junk rows id range by id range: no long locks, any database, any size (slow on huge tables). */
    private static function deleteInBatches(callable $log): int
    {
        $cache = CacheRecord::TABLE;
        $min = (int)DB::table($cache)->min('id');
        $max = (int)(DB::table($cache)->max('id') ?? -1);
        $removed = 0;
        for ($from = $min; $from <= $max; $from += self::BATCH) {
            $ids = DB::table($cache)
                ->whereBetween("$cache.id", [$from, $from + self::BATCH - 1])
                ->whereNot(fn(Builder $query) => self::keep($query))
                ->pluck("$cache.id")
                ->all();
            if ($ids) {
                $removed += DB::table($cache)->whereIntegerInRaw('id', $ids)->delete();
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
        $pgsql = DB::isPgsql();
        $grammar = DB::connection()->getQueryGrammar();
        $table = $grammar->wrapTable(CacheRecord::TABLE);
        $keep = $grammar->wrapTable(CacheRecord::TABLE . '_keep');
        $cache = CacheRecord::TABLE;
        // an exact count of a huge table would be one more full scan, for a log line
        $before = self::estimatedRows();
        $run = function () use ($pgsql, $table, $keep, $cache, $before, $log): int {
            // PostgreSQL: writers wait (reads go on until the truncate), and room to hash `elements` once per join
            if ($pgsql) {
                DB::statement("LOCK TABLE $table IN EXCLUSIVE MODE");
                DB::statement("SET LOCAL work_mem = '256MB'");
            }
            // keep() as joins: over the whole table the database hashes `elements` once per join, where the EXISTS
            // form checks every row
            $select = DB::table($cache)
                ->select("$cache.*")
                ->leftJoin(Table::ELEMENTS . ' as t', 't.id', '=', "$cache.targetElementId")
                ->leftJoin(Table::ELEMENTS . ' as s', 's.id', '=', "$cache.sourceElementId")
                ->leftJoin(Table::ELEMENTS . ' as o', 'o.id', '=', "$cache.sourcePrimaryOwnerId")
                ->where(fn(Builder $query) => $query
                    ->whereNull("$cache.sourceElementId")
                    ->orWhere(fn(Builder $live) => $live->whereNotNull('t.id')->whereNotNull('s.id')->whereNotNull('o.id')->whereNull('o.revisionId')));
            DB::statement("DROP TABLE IF EXISTS $keep");
            DB::statement("CREATE TEMPORARY TABLE $keep AS " . $select->toSql(), $select->getBindings());
            $kept = (int)DB::scalar("SELECT COUNT(*) FROM $keep");
            $log(sprintf('keeping %d of about %d rows', $kept, $before));
            DB::statement("TRUNCATE TABLE $table");
            DB::statement("INSERT INTO $table SELECT * FROM $keep");
            DB::statement("DROP TABLE $keep");
            DB::statement(($pgsql ? 'ANALYZE ' : 'ANALYZE TABLE ') . $table);
            return $kept;
        };
        $kept = $pgsql ? DB::transaction($run) : $run();
        $removed = max(0, $before - $kept);
        $log("removed about $removed rows");
        return $removed;
    }

    /** The database's estimate of the table's rows (no full count of a huge table); a count when it has none. */
    private static function estimatedRows(): int
    {
        $name = DB::getTablePrefix() . CacheRecord::TABLE;
        $estimate = DB::isPgsql()
            ? (int)DB::scalar('SELECT reltuples::bigint FROM pg_class WHERE oid = to_regclass(?)', [DB::connection()->getQueryGrammar()->wrapTable(CacheRecord::TABLE)])
            : (int)DB::scalar('SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$name]);
        return $estimate >= self::REBUILD_MIN_ROWS ? $estimate : DB::table(CacheRecord::TABLE)->count();
    }
}
