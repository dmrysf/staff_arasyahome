<?php

declare(strict_types=1);

// Read-only rollback preflight, executed by the current release before changing either release pointer.
// Never execute the target release's checker: an older package may not recognize current per-piece state.
use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\Connection;

if (PHP_SAPI !== 'cli' || count($argv) !== 2 || preg_match('/^\d+\.\d+\.\d+$/D', $argv[1]) !== 1) {
    fwrite(STDERR, "Usage: php bin/check-rollback.php <target-api-version>\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap.php';
try {
    $pdo = Connection::create(Config::fromEnvironment());
    $tables = array_flip($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    $required = ['schema_migrations', 'production_tracks', 'production_work_units', 'production_feature_flags',
        'production_unit_replacements', 'production_line_plan_heads', 'production_route_decisions',
        'production_unit_verifications', 'production_pilot_cohort', 'production_material_records', 'production_unit_custody'];
    foreach ($required as $table) {
        if (!isset($tables[$table])) throw new RuntimeException('Incomplete production schema.');
    }
    $migration = $pdo->query("SELECT checksum FROM schema_migrations WHERE migration_name = '029_production_manifest_routing.sql'")->fetchColumn();
    if (!is_string($migration) || !hash_equals(hash_file('sha256', dirname(__DIR__) . '/database/migrations/029_production_manifest_routing.sql'), $migration)) {
        throw new RuntimeException('Current migration could not be verified.');
    }
    // Migration 030 (verified historical stage evidence) is optional for the guard, but when it is recorded it must be the
    // known file, and its evidence counts as per-piece state.
    $reconciliation = $pdo->query("SELECT checksum FROM schema_migrations WHERE migration_name = '030_production_route_reconciliation.sql'")->fetchColumn();
    if (is_string($reconciliation) && (!isset($tables['production_route_reconciliations']) || !hash_equals(hash_file('sha256', dirname(__DIR__) . '/database/migrations/030_production_route_reconciliation.sql'), $reconciliation))) {
        throw new RuntimeException('Current migration could not be verified.');
    }
    // Migration 031 (assisted PERDEA checkpoints) likewise: optional, verified when recorded. An open order with a checkpoint
    // needs API 2.34.0 or newer, which still requires the verification of its PERDEA pieces at stages 6 and 7.
    $assisted = $pdo->query("SELECT checksum FROM schema_migrations WHERE migration_name = '031_production_assisted_checkpoints.sql'")->fetchColumn();
    if (is_string($assisted) && (!isset($tables['production_assisted_checkpoints']) || !hash_equals(hash_file('sha256', dirname(__DIR__) . '/database/migrations/031_production_assisted_checkpoints.sql'), $assisted))) {
        throw new RuntimeException('Current migration could not be verified.');
    }
    $state = [];
    $assistedOpen = isset($tables['production_assisted_checkpoints'])
        ? (int) $pdo->query('SELECT COUNT(DISTINCT c.order_uuid) FROM production_assisted_checkpoints c INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid WHERE o.production_completed_at IS NULL')->fetchColumn() : 0;
    if (isset($tables['production_assisted_checkpoints'])) $state['production_assisted_checkpoints'] = (int) $pdo->query('SELECT COUNT(*) FROM production_assisted_checkpoints')->fetchColumn();
    if (isset($tables['production_route_reconciliations'])) $state['production_route_reconciliations'] = (int) $pdo->query('SELECT COUNT(*) FROM production_route_reconciliations')->fetchColumn();
    if (isset($tables['production_tracks'])) $state['activeTracks'] = (int) $pdo->query("SELECT COUNT(*) FROM production_tracks WHERE status = 'active'")->fetchColumn();
    foreach (['production_work_units', 'production_unit_replacements', 'production_line_plan_heads', 'production_route_decisions', 'production_unit_verifications', 'production_material_records', 'production_unit_custody'] as $table) {
        if (isset($tables[$table])) $state[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
    if (isset($tables['production_feature_flags'])) $state['enabledFeatures'] = (int) $pdo->query('SELECT COUNT(*) FROM production_feature_flags WHERE enabled = 1')->fetchColumn();
    if (isset($tables['production_pilot_cohort'])) $state['activeCohort'] = (int) $pdo->query("SELECT COUNT(*) FROM production_pilot_cohort WHERE status = 'active'")->fetchColumn();
    $floor = $assistedOpen > 0 ? '2.34.0' : (array_sum($state) > 0 ? '2.32.0' : null);
    $safe = $floor === null || version_compare($argv[1], $floor, '>=');
    fwrite(STDOUT, json_encode(['safe' => $safe, 'targetVersion' => $argv[1], 'requiredFloor' => $floor, 'state' => $state + ['openOrdersWithAssistedCheckpoints' => $assistedOpen]], JSON_THROW_ON_ERROR) . "\n");
    exit($safe ? 0 : 3);
} catch (Throwable) {
    fwrite(STDERR, "Rollback refused: current database state could not be verified.\n");
    exit(1);
}
