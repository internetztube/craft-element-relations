<?php

declare(strict_types=1);

namespace internetztube\elementRelations\migrations;

use craft\db\Migration;
use internetztube\elementRelations\services\PruneService;

/**
 * Removes the cache rows of elements Craft's garbage collection deleted (and of revisions) that earlier versions
 * left behind; from now on every garbage collection does (see PruneService). `up()`, not `safeUp()`: the rebuild
 * of a large PostgreSQL table runs its own transaction.
 */
class m261026_000000_prune_cache extends Migration
{
    public function up(bool $throwExceptions = false): bool
    {
        try {
            PruneService::prune(fn(string $line) => print("    > element relations cache: $line\n"));
        } catch (\Throwable $e) {
            if ($throwExceptions) {
                throw $e;
            }
            echo '    > element relations cache: ' . $e->getMessage() . "\n";
            return false;
        }
        return true;
    }

    public function down(bool $throwExceptions = false): bool
    {
        return true; // the rows were unreadable
    }
}
