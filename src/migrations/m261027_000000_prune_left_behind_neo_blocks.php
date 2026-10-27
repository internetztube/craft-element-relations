<?php

declare(strict_types=1);

namespace internetztube\elementRelations\migrations;

use craft\db\Migration;
use internetztube\elementRelations\services\PruneService;

/**
 * Removes the cache rows of Neo blocks a field converted to Matrix left behind (and of entries nested in them), which
 * earlier versions listed as usages of their owner; garbage collection would remove them too, but at no foreseeable
 * time. `up()`, not `safeUp()`: the rebuild of a large PostgreSQL table runs its own transaction.
 */
class m261027_000000_prune_left_behind_neo_blocks extends Migration
{
    public function up(bool $throwExceptions = false): bool
    {
        // Craft's migrator runs this with $throwExceptions and reports what is thrown
        PruneService::prune(function (string $line): void {
            echo "    > element relations cache: $line\n";
        });
        return true;
    }

    public function down(bool $throwExceptions = false): bool
    {
        return true; // the rows were no usages
    }
}
