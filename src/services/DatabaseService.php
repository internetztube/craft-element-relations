<?php

namespace internetztube\elementRelations\services;

use Craft;

class DatabaseService
{
    private static bool $isMaria;

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
        if (!isset(self::$isMaria)) {
            $connection = Craft::$app->db;
            self::$isMaria = $connection->getIsMysql() && str_contains(strtolower($connection->getSchema()->getServerVersion()), 'mariadb');
        }
        return self::$isMaria;
    }
}