<?php
// Explicit maintenance command; never run implicitly on container startup.
if (PHP_SAPI !== 'cli' || count($argv) !== 3) {
    fwrite(STDERR, "Usage: php repair-plugins.php <hostname> <expected-database>\n");
    exit(2);
}
chdir('/var/www/html');
require 'init.php';
if ($GLOBALS['_MAX']['CONF']['database']['name'] !== $argv[2]) {
    fwrite(STDERR, "Unexpected database; refusing plugin repair\n");
    exit(2);
}
if ($GLOBALS['_MAX']['CONF']['database']['type'] !== 'pgsql') {
    fwrite(STDERR, "This recovery command requires PostgreSQL\n");
    exit(2);
}
require_once 'lib/OA/Admin/Settings.php';
require_once 'lib/OX/Plugin/PluginManager.php';
foreach (glob(MAX_PATH . '/etc/plugins/*.zip') as $archive) {
    $name = basename($archive, '.zip');
    $manager = new OX_PluginManager();
    $file = ['tmp_name' => $archive, 'name' => basename($archive)];
    $geo = $GLOBALS['_MAX']['CONF']['rvMaxMindGeoIP2'] ?? [];
    $enable = !($name === 'reviveMaxMindGeoIP2' &&
        empty($geo['mmdb_paths']) &&
        (empty($geo['account_id']) || empty($geo['license_key'])));
    // GeoIP onEnable performs a network download without a timeout. When no
    // database or download credentials are configured, keep it disabled before
    // upgrade as well: upgradePackage re-enables an already enabled package.
    if (!$enable && !$manager->disablePackage($name, true)) {
        fwrite(STDERR, "Cannot disable unconfigured GeoIP package\n");
        exit(1);
    }
    if (!$manager->_parsePackage($name) ||
        !$manager->_parseComponentGroups($manager->aParse['package']['install']['contents'])) {
        fwrite(STDERR, "Cannot parse bundled package $name\n");
        exit(1);
    }
    // Restore only missing settings from the package's own defaults. Existing
    // settings (SMTP credentials, geo paths, cache servers) must be preserved.
    foreach ($manager->aParse['plugins'] as $group) {
        $defaults = [];
        foreach ($group['install']['conf']['settings'] ?? [] as $setting) {
            if (!array_key_exists($setting['key'], $GLOBALS['_MAX']['CONF'][$group['name']] ?? [])) {
                $defaults[] = $setting;
            }
        }
        if ($defaults && !$manager->_registerSettings($group['name'], $defaults)) {
            fwrite(STDERR, "Cannot restore settings for {$group['name']}\n");
            exit(1);
        }
        $schema = $group['install']['schema'] ?? [];
        if (!empty($schema['mdb2schema'])) {
            $tableDefinition = new OA_DB_Table();
            if (!$tableDefinition->init($manager->getFilePathToMDB2Schema($group['name'], $schema['mdb2schema']), false)) {
                fwrite(STDERR, "Cannot read schema for {$group['name']}\n");
                exit(1);
            }
            $db = OA_DB::singleton();
            $present = 0;
            $tables = array_keys($tableDefinition->aDefinition['tables']);
            foreach ($tables as $table) {
                $result = $db->queryOne('SELECT to_regclass(' . $db->quote($GLOBALS['_MAX']['CONF']['table']['prefix'] . $table) . ')');
                if (PEAR::isError($result)) {
                    fwrite(STDERR, "Cannot inspect plugin schema\n");
                    exit(1);
                }
                $present += $result !== null ? 1 : 0;
            }
            // Recreate only a wholly absent schema. A partially present schema
            // requires manual review; never drop or overwrite existing tables.
            if ($present === 0 && count($tables) > 0) {
                if (!$manager->_registerSchema($group['name'], $schema) ||
                    !$manager->_registerPluginVersion($group['name'], $group['version'])) {
                    fwrite(STDERR, "Cannot restore schema for {$group['name']}\n");
                    exit(1);
                }
                echo "RESTORED SCHEMA {$group['name']}\n";
            } elseif ($present !== count($tables)) {
                fwrite(STDERR, "Partial schema for {$group['name']}; refusing repair\n");
                exit(1);
            }
        }
    }
    // Revive's upgrade API consults stored component versions and installs
    // missing groups. Equal package versions must be allowed when restoring
    // lost configuration on an already upgraded core database.
    if (!$manager->upgradePackage($file, $name, true) ||
        ($enable && !$manager->enablePackage($name, true))) {
        fwrite(STDERR, 'FAILED ' . $name . ': ' . json_encode($manager->aErrors) . "\n");
        exit(1);
    }
    echo $enable ? "REPAIRED $name\n" : "REPAIRED $name (disabled: GeoIP is unconfigured)\n";
}
