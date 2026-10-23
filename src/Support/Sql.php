<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Support;

use Illuminate\Support\Facades\DB;

/** Identifiers for raw SQL fragments (COUNT(DISTINCT …), CASE …): quoted and prefixed like the query builder does. */
final class Sql
{
    /** `table.column` as the connection's grammar writes it (`"craft_elements"."id"`). */
    public static function col(string $column): string
    {
        return DB::connection()->getQueryGrammar()->wrap($column);
    }
}
