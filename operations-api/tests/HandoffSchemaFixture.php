<?php
declare(strict_types=1);
/** Restore a pre-013 disposable test fixture (no project workspace). This is NOT a deployment/rollback mechanism. */
function restorePreProjectsTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    foreach(['b2b_project_idempotency','b2b_project_activity_events','b2b_project_order_lines','b2b_project_orders','b2b_project_treatments','b2b_project_openings',
        'b2b_project_rooms','b2b_project_zones','b2b_projects','b2b_project_number_sequence'] as $table) $pdo->exec('DROP TABLE IF EXISTS '.$table);
    $pdo->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.permission_id=rp.permission_id WHERE p.permission_key LIKE 'b2b.projects.%'");
    $pdo->exec("DELETE FROM permissions WHERE permission_key LIKE 'b2b.projects.%'");
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='013_b2b_projects.sql'");
}

/** Restore a pre-012 disposable test fixture. This is NOT a deployment/rollback mechanism. */
function restorePreHandoffTestSchema(PDO $pdo): void
{
    restorePreProjectsTestSchema($pdo);
    if(!$pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='012_b2b_production_handoff.sql'")->fetchColumn()) return;
    $pdo->exec('DROP TABLE b2b_production_handoffs');
    $pdo->exec('ALTER TABLE operational_orders DROP INDEX uq_operational_handoff_identity, DROP COLUMN production_context');
    $pdo->exec('ALTER TABLE operational_order_items DROP COLUMN production_context');
    $pdo->exec('ALTER TABLE order_activity_events DROP CONSTRAINT chk_order_activity_action');
    $pdo->exec("DELETE FROM order_activity_events WHERE action='production_submitted'");
    $pdo->exec("ALTER TABLE order_activity_events ADD CONSTRAINT chk_order_activity_action CHECK(action IN ('claimed','stage_completed','production_completed','owner_released','owner_reassigned'))");
    $pdo->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.permission_id=rp.permission_id WHERE p.permission_key LIKE 'b2b.production.%'");
    $pdo->exec("DELETE FROM permissions WHERE permission_key LIKE 'b2b.production.%'");
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='012_b2b_production_handoff.sql'");
}
