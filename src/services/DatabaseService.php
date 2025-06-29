<?php

namespace internetztube\elementRelations\services;

use Craft;

class DatabaseService
{
    /**
     * Cached result of the MariaDB check. `null` indicates it has not been
     * determined yet.
     */
    private static ?bool $isMaria = null;

    public static function jsonExtract(string $column, array $path): string
    {
        $db = Craft::$app->getDb();
        $queryBuilder = $db->getQueryBuilder();
        if (method_exists($queryBuilder, 'jsonExtract')) {
            return $queryBuilder->jsonExtract($column, $path);
        }

        // Craft 4 Fallback
        $column = $db->quoteColumnName($column);

        if ($db->getIsMysql()) {
            $path = $db->quoteValue(
                sprintf('$.%s', implode('.', array_map(fn(string $seg) => sprintf('"%s"', $seg), $path)))
            );
            // Maria doesn't support ->/->> operators :(
            if (self::getIsMaria()) {
                return "JSON_UNQUOTE(JSON_EXTRACT($column, $path))";
            }
            return "($column->>$path)";
        }

        if ($db->getIsPgsql()) {
            $path = $db->quoteValue(
                sprintf('{%s}', implode(',', array_map(fn(string $seg) => sprintf('"%s"', $seg), $path)))
            );

            return "($column::json#>>$path)";
        }
        return "";
    }

    private static function getIsMaria(): bool
    {
        if (self::$isMaria === null) {
            $connection = Craft::$app->db;
            self::$isMaria = $connection->getIsMysql()
                && str_contains(strtolower($connection->getSchema()->getServerVersion()), 'mariadb');
        }

        // self::$isMaria is guaranteed to be a bool here.
        return self::$isMaria;
    }
}