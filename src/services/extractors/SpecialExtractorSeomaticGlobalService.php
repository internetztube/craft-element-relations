<?php

namespace internetztube\elementRelations\services\extractors;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Asset;

class SpecialExtractorSeomaticGlobalService
{
    public static function isInUse(ElementInterface $element): bool
    {
        if (!Craft::$app->plugins->isPluginEnabled('seomatic') || !($element instanceof Asset)) {
            return false;
        }
        $columnSelector = self::dbJsonExtract("metaBundleSettings", ["seoImageIds"]);

        return (new Query())
            ->from([\nystudio107\seomatic\records\MetaBundle::tableName()])
            ->where(['=', $columnSelector, "[\"$element->id\"]"])
            ->collect()
            ->isNotEmpty();
    }

    public static function dbJsonExtract(string $column, array $path): string
    {
        $db = Craft::$app->getDb();
        $column = $db->quoteColumnName($column);

        if ($db->getIsMysql()) {
            $path = $db->quoteValue(
                sprintf('$.%s', implode('.', array_map(fn(string $seg) => sprintf('"%s"', $seg), $path)))
            );
            // Maria doesn't support ->/->> operators :(
            if ($db->getIsMaria()) {
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
}