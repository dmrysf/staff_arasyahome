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
    // Migration 031 (PERDEA auxiliary-work status) likewise: optional, verified when recorded. An open order with a status
    // row follows route policy version 2; an API older than 2.34.0 would re-route it with version 1 mid-route.
    $assisted = $pdo->query("SELECT checksum FROM schema_migrations WHERE migration_name = '031_production_assisted_checkpoints.sql'")->fetchColumn();
    if (is_string($assisted) && (!isset($tables['production_assisted_checkpoints']) || !hash_equals(hash_file('sha256', dirname(__DIR__) . '/database/migrations/031_production_assisted_checkpoints.sql'), $assisted))) {
        throw new RuntimeException('Current migration could not be verified.');
    }
    // Migration 033 (cancellation control) likewise: optional, verified when recorded. An API older than 2.35.0 ignores
    // production stops, so an open stopped order would become workable again: refused while any stop is in force.
    $cancellation = $pdo->query("SELECT checksum FROM schema_migrations WHERE migration_name = '033_order_cancellation_control.sql'")->fetchColumn();
    if (is_string($cancellation) && (!isset($tables['order_cancellation_controls']) || !hash_equals(hash_file('sha256', dirname(__DIR__) . '/database/migrations/033_order_cancellation_control.sql'), $cancellation))) {
        throw new RuntimeException('Current migration could not be verified.');
    }
    // Migration 034 (cancellation synchronization) likewise. An API older than 2.36.0 would release a stop while a
    // cancellation request may still be acted on by the sales channel: refused while any operation is open.
    $sync = $pdo->query("SELECT checksum FROM schema_migrations WHERE migration_name = '034_order_cancellation_sync.sql'")->fetchColumn();
    if (is_string($sync) && (!isset($tables['order_cancellation_sync_operations']) || !hash_equals(hash_file('sha256', dirname(__DIR__) . '/database/migrations/034_order_cancellation_sync.sql'), $sync))) {
        throw new RuntimeException('Current migration could not be verified.');
    }
    $syncOpen = isset($tables['order_cancellation_sync_operations'])
        ? (int) $pdo->query('SELECT COUNT(*) FROM order_cancellation_sync_operations WHERE open_order_uuid IS NOT NULL')->fetchColumn() : 0;
    $stoppedOpen = isset($tables['order_cancellation_controls'])
        ? (int) $pdo->query("SELECT COUNT(*) FROM operational_orders WHERE cancellation_state = 'held' AND production_completed_at IS NULL")->fetchColumn() : 0;
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
    // Production ticket schema 2 (API 2.34.2, Trendyol documents only): an older API compares every document in schema 1,
    // so an open order whose revision or open request was generated in schema 2 would go stale (production blocked) on
    // its next source event.
    $schemaTwoOpen = (int) $pdo->query(
        'SELECT COUNT(*) FROM operational_orders o WHERE o.production_completed_at IS NULL AND (
            EXISTS (SELECT 1 FROM production_document_revisions r WHERE r.order_uuid = o.order_uuid AND r.snapshot_schema >= 2)
            OR EXISTS (SELECT 1 FROM production_document_revision_requests q WHERE q.open_order_uuid = o.order_uuid AND q.snapshot_schema >= 2))',
    )->fetchColumn();
    // Confirmare (API 2.37.0 to 2.42.0): an API older than 2.37.0 ignores the cohort, so it would let an order waiting for
    // its Confirmare decision (or for an operator resolution) start production. 2.37.x only checks the approval: the
    // financial, contact and fingerprint clearance (2.38.0 to 2.41.0) and the first production document required before
    // cutting (2.42.0) exist only in later releases. So every open Confirmare or undetermined order, approved or not, and
    // any recorded activation (an older API would keep receiving that source's orders without the full rules) require
    // 2.42.0.
    $cohortClosed = 0;
    $cohortOpen = 0;
    $activations = isset($tables['workflow_cohort_activations']) ? (int) $pdo->query('SELECT COUNT(*) FROM workflow_cohort_activations')->fetchColumn() : 0;
    if (isset($tables['order_confirmations'])) {
        $cohortClosed = (int) $pdo->query(
            "SELECT COUNT(*) FROM operational_orders o LEFT JOIN order_confirmations c ON c.order_uuid = o.order_uuid
             WHERE o.workflow_cohort IN ('confirmare', 'undetermined') AND o.production_completed_at IS NULL
               AND (o.workflow_cohort = 'undetermined' OR c.status IS NULL OR c.status <> 'approved')",
        )->fetchColumn();
        $cohortOpen = (int) $pdo->query(
            "SELECT COUNT(*) FROM operational_orders WHERE workflow_cohort IN ('confirmare', 'undetermined') AND production_completed_at IS NULL",
        )->fetchColumn();
    }
    $floor = ($cohortOpen > 0 || $activations > 0) ? '2.42.0' : ($syncOpen > 0 ? '2.36.0' : ($stoppedOpen > 0 ? '2.35.0' : ($schemaTwoOpen > 0 ? '2.34.2' : ($assistedOpen > 0 ? '2.34.0' : (array_sum($state) > 0 ? '2.32.0' : null)))));
    $safe = $floor === null || version_compare($argv[1], $floor, '>=');
    fwrite(STDOUT, json_encode(['safe' => $safe, 'targetVersion' => $argv[1], 'requiredFloor' => $floor, 'state' => $state + ['openOrdersWithAssistedCheckpoints' => $assistedOpen, 'openOrdersWithTicketSchema2' => $schemaTwoOpen, 'ordersAwaitingConfirmare' => $cohortClosed, 'openConfirmareOrders' => $cohortOpen, 'confirmareActivations' => $activations, 'openStoppedOrders' => $stoppedOpen, 'openCancellationSyncOperations' => $syncOpen]], JSON_THROW_ON_ERROR) . "\n");
    exit($safe ? 0 : 3);
} catch (Throwable) {
    fwrite(STDERR, "Rollback refused: current database state could not be verified.\n");
    exit(1);
}
