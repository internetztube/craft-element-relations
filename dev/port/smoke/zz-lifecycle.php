<?php
/**
 * The plugin's own life cycle, last (it drops and rebuilds the plugin's tables): settings validation, uninstall and
 * install, the upgrade from 2.x (its schema, then every 3.x migration) against a fresh install, and the full cache
 * rebuild from the command line, which must give back exactly the rows the cache had.
 */

use craft\db\Query;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\migrations\Install;
use internetztube\elementRelations\models\SettingsModel;

return function (array $ctx): void {
    $db = Craft::$app->getDb();
    $plugins = Craft::$app->getPlugins();
    $tables = ['{{%elementrelations_cache}}', '{{%elementrelations_replacements}}', '{{%elementrelations_replacement_changes}}'];

    // --- settings
    check(!(new SettingsModel(['bulkRefreshBatchSize' => 0]))->validate() && (new SettingsModel(['bulkRefreshBatchSize' => 2000]))->validate(), 'settings: the batch size must be a positive integer');

    /** The plugin's tables as columns (type, size, null) and indexes (column lists), comparable across installs. */
    $schema = function () use ($db, $tables): array {
        $db->getSchema()->refresh();
        $out = [];
        foreach ($tables as $table) {
            $tableSchema = $db->getTableSchema($table, true);
            if ($tableSchema === null) {
                $out[$table] = null;
                continue;
            }
            $columns = [];
            foreach ($tableSchema->columns as $column) {
                $columns[$column->name] = "$column->type/$column->size/" . ($column->allowNull ? 'null' : 'not null');
            }
            ksort($columns);
            $indexes = array_map(fn($index): string => implode(',', $index->columnNames ?? []), $db->getSchema()->getTableIndexes($table, true));
            $indexes = array_values(array_unique($indexes));
            sort($indexes);
            $out[$table] = ['columns' => $columns, 'indexes' => $indexes];
        }
        return $out;
    };
    /** What the cache holds, independent of row ids and dates: one string per row, sorted. */
    $cacheRows = function () use ($db): array {
        $db->getSchema()->refresh();
        $rows = array_map(fn(array $row): string => implode('|', array_map(fn($value): string => (string)$value, $row)), (new Query())
            ->select(['sourceElementId', 'sourceSiteId', 'sourcePrimaryOwnerId', 'targetElementId', 'targetSiteId', 'fieldId', 'type', 'globalKey'])
            ->from('{{%elementrelations_cache}}')->all());
        sort($rows);
        return $rows;
    };
    /** What is missing or extra, by owner and site: type, draft, trashed, title, so a failure says which elements. */
    $diff = function (array $a, array $b): string {
        $describe = function (array $rows): array {
            $out = [];
            foreach ($rows as $row) {
                [, $site, $owner] = explode('|', $row);
                if (isset($out["$owner:$site"]) || count($out) >= 8) {
                    continue;
                }
                $element = (new Query())->from('{{%elements}}')->where(['id' => (int)$owner])->one();
                $draft = $element && $element['draftId'] ? (new Query())->from('{{%drafts}}')->where(['id' => $element['draftId']])->one() : null;
                $title = (new Query())->from('{{%elements_sites}}')->where(['elementId' => (int)$owner, 'siteId' => (int)$site])->select('title')->scalar();
                $out["$owner:$site"] = $element ? sprintf('%s “%s” %s%s', $element['type'], $title, $draft ? ($draft['provisional'] ? 'provisional draft ' : 'draft ') : '', $element['dateDeleted'] ? 'trashed' : '') : 'gone';
            }
            return $out;
        };
        return json_encode(['missing' => $describe(array_diff($a, $b)), 'extra' => $describe(array_diff($b, $a))], JSON_UNESCAPED_UNICODE) ?: '';
    };

    $before = $cacheRows();
    check(count($before) > 100, 'the cache is filled before (' . count($before) . ' rows)');
    $edition = ElementRelations::getInstance()->edition;

    // --- uninstall and install, like a user does
    check($plugins->uninstallPlugin('element-relations'), 'uninstall succeeds');
    check(array_filter($schema()) === [], 'uninstall drops every table of the plugin');
    check($plugins->installPlugin('element-relations', $edition), 'install succeeds');
    $fresh = $schema();
    check(!in_array(null, $fresh, true), 'install creates every table');
    flushQueue(); // the install queues the full rebuild
    $rebuilt = $cacheRows();
    check($rebuilt === $before, 'after reinstalling, the queued rebuild gives back exactly the rows the cache had (' . count($rebuilt) . ' of ' . count($before) . ') ' . $diff($before, $rebuilt));

    // --- the upgrade from 2.x: its schema, a leftover 1.x table, then every 3.x migration
    (new Install())->safeDown();
    check(array_filter($schema()) === [], 'the install migration drops what it created');
    /** Runs a migration's up or down quietly; false when it says it cannot. */
    $migrate = function (string $name, string $direction = 'up'): bool {
        $class = "internetztube\\elementRelations\\migrations\\$name";
        $migration = new $class();
        $migration->compact = true;
        ob_start();
        $ok = $migration->$direction() !== false;
        ob_end_clean();
        return $ok;
    };
    // the plugin's tables are gone (e.g. uninstalled before an update): the migrations that only change them do nothing
    foreach (['m261021_000000_replacement_revisions', 'm261023_000000_replacement_batches', 'm261024_000000_cache_indexes', 'm261025_000000_replacement_changes', 'm261026_000000_prune_cache'] as $name) {
        check($migrate($name) && array_filter($schema()) === [], "without the plugin's tables, $name does nothing");
    }
    $db->createCommand()->createTable('{{%elementrelations}}', ['id' => 'pk'])->execute(); // what 1.x left behind
    $migrations = [
        'm240601_132533_drop', 'm240616_122033_create_elementrelations_cache_table', 'm241004_144031_optimize_index_elementrelations_table',
        'm261019_120000_global_usages', 'm261020_000000_replacements', 'm261021_000000_replacement_revisions',
        'm261023_000000_replacement_batches', 'm261024_000000_cache_indexes', 'm261025_000000_replacement_changes',
        'm261026_000000_prune_cache',
    ];
    $twoX = null;
    foreach ($migrations as $name) {
        check($migrate($name), "upgrade: $name runs");
        if ($name === 'm241004_144031_optimize_index_elementrelations_table') {
            $twoX = $schema(); // what a 2.x install has
            // `craft up` from 3.x: Craft's own migrations run first, and one that saves sections makes SEOmatic save
            // its bundles, before the plugin's migrations added the column for those rows
            $bundle = class_exists(\nystudio107\seomatic\records\MetaBundle::class) ? \nystudio107\seomatic\records\MetaBundle::find()->one() : null;
            $saved = null;
            try {
                $saved = $bundle?->save(false) ?? true;
            } catch (Throwable $e) {
                $saved = $e->getMessage();
            }
            check($saved === true, 'upgrade from 3.x: SEOmatic saving its settings before the plugin\'s migrations ran does not fail (' . substr((string)json_encode($saved), 0, 160) . ')');
        }
        if ($name === 'm261020_000000_replacements') {
            // as the first 3.2 development builds left it: no revisions, plan or state columns, shorter text columns,
            // the changes still in a JSON column instead of their own table
            $db->createCommand()->dropTable('{{%elementrelations_replacement_changes}}')->execute();
            foreach (['revisions', 'plan', 'state'] as $column) {
                $db->createCommand()->dropColumn('{{%elementrelations_replacements}}', $column)->execute();
            }
            foreach (['sourceIds', 'result'] as $column) {
                $db->createCommand()->alterColumn('{{%elementrelations_replacements}}', $column, 'text')->execute();
            }
            $db->getSchema()->refresh();
        }
    }
    $db->getSchema()->refresh();
    check(!$db->tableExists('{{%elementrelations}}'), "upgrade: 1.x's table is dropped");
    $upgraded = $schema();
    check($upgraded === $fresh, 'upgrade: the 2.x schema (and an early 3.2 one) migrated to 3.x is the schema of a fresh install ' . ($upgraded === $fresh ? '' : json_encode(['fresh' => $fresh, 'upgraded' => $upgraded])));
    // 3.x's own migrations a second time: each checks before it changes anything
    foreach (array_slice($migrations, 3) as $name) {
        check($migrate($name), "upgrade: $name runs again without changing anything");
    }
    check($schema() === $fresh, 'upgrade: running them again leaves the schema as it is');

    // and back: 3.x's migrations revert to 2.x's tables and columns; the 2.x ones say when they cannot be reverted
    foreach (array_reverse(array_slice($migrations, 3)) as $name) {
        check($migrate($name, 'down'), "downgrade: $name reverts");
    }
    $reverted = $schema();
    $columnsOf = fn(array $schema): array => array_map(fn(?array $table): ?array => $table['columns'] ?? null, $schema);
    check($columnsOf($reverted) === $columnsOf($twoX), "downgrade: the tables and columns are 2.x's again " . ($columnsOf($reverted) === $columnsOf($twoX) ? '' : json_encode(['2.x' => $twoX, 'reverted' => $reverted])));
    check($migrate('m241004_144031_optimize_index_elementrelations_table', 'down') && !$migrate('m240616_122033_create_elementrelations_cache_table', 'down') && !$migrate('m240601_132533_drop', 'down'), "downgrade: 2.x's index migration reverts, the ones that cannot say so");
    foreach (array_slice($migrations, 2) as $name) {
        check($migrate($name), "upgrade again: $name runs");
    }
    $again = $schema();
    check($again === $fresh, 'upgrade again: the schema of a fresh install ' . ($again === $fresh ? '' : json_encode(['fresh' => $fresh, 'again' => $again])));

    // --- the full rebuild from the command line
    $command = [...phpCommand(), CRAFT_BASE_PATH . '/craft', 'element-relations/resave-relations', '--color=0'];
    exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $lines, $code);
    check($code === 0, 'resave-relations queues the full rebuild (' . trim(implode(' ', $lines)) . ')');
    flushQueue();
    $rebuilt = $cacheRows();
    check($rebuilt === $before, 'after the upgrade, the full rebuild gives back exactly the rows the cache had (' . count($rebuilt) . ' of ' . count($before) . ') ' . $diff($before, $rebuilt));
};
