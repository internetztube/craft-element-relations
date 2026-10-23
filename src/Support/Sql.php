<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Support;

use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/** Identifiers for raw SQL fragments (COUNT(DISTINCT …), CASE …): quoted and prefixed like the query builder does. */
final class Sql
{
    /** `table.column` as the connection's grammar writes it (`"craft_elements"."id"`). */
    public static function col(string $column): string
    {
        return DB::connection()->getQueryGrammar()->wrap($column);
    }

    /**
     * A raw SQL fragment built from col() identifiers and integers only, never from input.
     * @return Expression<literal-string>
     */
    public static function raw(string $sql): Expression
    {
        /** @var literal-string $sql see above */
        return new Expression($sql);
    }
}
