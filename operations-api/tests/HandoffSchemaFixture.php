<?php
declare(strict_types=1);
/** Restore a pre-022 disposable test fixture (no Trendyol intake). This is NOT a deployment/rollback mechanism. */
function restorePreTrendyolIntakeTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    if(!$pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='022_trendyol_intake.sql'")->fetchColumn()) return;
    foreach(['trendyol_intake_events','trendyol_package_lines','trendyol_packages','trendyol_ignored_packages','trendyol_sync_runs','trendyol_intake_state'] as $table) $pdo->exec("DROP TABLE IF EXISTS {$table}");
    $pdo->exec("DELETE FROM production_exception_idempotency WHERE operation LIKE 'trendyol.%'");
    $pdo->exec("DELETE era FROM employee_role_assignments era JOIN roles r ON r.role_id=era.role_id WHERE r.role_key IN ('trendyol-order-preparer','trendyol-order-approver')");
    $pdo->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.permission_id=rp.permission_id WHERE p.permission_key LIKE 'trendyol.%'");
    $pdo->exec("DELETE FROM roles WHERE role_key IN ('trendyol-order-preparer','trendyol-order-approver')");
    $pdo->exec("DELETE FROM permissions WHERE permission_key LIKE 'trendyol.%'");
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='022_trendyol_intake.sql'");
}

/** Restore a pre-021 disposable test fixture (no document scopes). This is NOT a deployment/rollback mechanism. */
function restorePreDocumentScopesTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    restorePreTrendyolIntakeTestSchema($pdo);
    if(!$pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='021_document_scopes.sql'")->fetchColumn()) return;
    $pdo->exec('DROP TABLE IF EXISTS employee_document_scopes');
    $pdo->exec('ALTER TABLE live_events DROP COLUMN scope_source_key');
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='021_document_scopes.sql'");
}

/** Restore a pre-020 disposable test fixture (no source-attributed documents). This is NOT a deployment/rollback mechanism. */
function restorePreDocumentAuthorityTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    restorePreDocumentScopesTestSchema($pdo);
    if(!$pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='020_production_document_authority.sql'")->fetchColumn()) return;
    $pdo->exec('DELETE FROM production_document_prints WHERE printed_by_employee_uuid IS NULL');
    $pdo->exec('ALTER TABLE production_document_prints DROP CONSTRAINT chk_production_document_prints_printer');
    $pdo->exec('ALTER TABLE production_document_prints DROP COLUMN printed_by_source_actor, DROP COLUMN printed_by_source_key, MODIFY printed_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
    $pdo->exec('ALTER TABLE production_document_revisions DROP CONSTRAINT chk_production_document_revisions_generator');
    $pdo->exec('ALTER TABLE production_document_revisions DROP COLUMN generated_by_source_actor, DROP COLUMN generated_by_source_key');
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='020_production_document_authority.sql'");
}

/** Restore a pre-019 disposable test fixture (no production QR authority). This is NOT a deployment/rollback mechanism. */
function restorePreQrAuthorityTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    restorePreDocumentAuthorityTestSchema($pdo);
    if(!$pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='019_production_qr_authority.sql'")->fetchColumn()) return;
    $pdo->exec('DROP TABLE IF EXISTS production_qr_events');
    $pdo->exec('ALTER TABLE order_qr_references DROP CONSTRAINT chk_order_qr_references_retired');
    $pdo->exec('ALTER TABLE order_qr_references DROP INDEX uq_order_qr_references_active, DROP COLUMN active_order_uuid, DROP COLUMN retired_reason');
    $pdo->exec('ALTER TABLE order_operation_idempotency DROP CONSTRAINT chk_order_operation_idempotency_operation');
    $pdo->exec("DELETE FROM order_operation_idempotency WHERE operation = 'qr_rotate'");
    $pdo->exec("ALTER TABLE order_operation_idempotency ADD CONSTRAINT chk_order_operation_idempotency_operation CHECK (operation IN ('claim', 'transition', 'release_owner', 'reassign_owner', 'authority_takeover', 'authority_release'))");
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='019_production_qr_authority.sql'");
}

/** Restore a pre-018 disposable test fixture (no production authority control plane). This is NOT a deployment/rollback mechanism. */
function restorePreAuthorityTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    restorePreQrAuthorityTestSchema($pdo);
    if(!$pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='018_production_authority.sql'")->fetchColumn()) return;
    $pdo->exec('DROP TABLE IF EXISTS production_authority_events');
    $pdo->exec('ALTER TABLE order_activity_events DROP CONSTRAINT chk_order_activity_action');
    $pdo->exec("DELETE FROM order_activity_events WHERE action IN ('authority_taken_over','authority_released')");
    $pdo->exec("ALTER TABLE order_activity_events ADD CONSTRAINT chk_order_activity_action CHECK (action IN ('claimed', 'stage_completed', 'production_completed', 'owner_released', 'owner_reassigned', 'production_submitted', 'fault_reported', 'fault_rejected', 'fault_rereview_requested', 'fault_returned', 'fault_cancelled'))");
    $pdo->exec('ALTER TABLE order_operation_idempotency DROP CONSTRAINT chk_order_operation_idempotency_operation');
    $pdo->exec("DELETE FROM order_operation_idempotency WHERE operation IN ('authority_takeover','authority_release')");
    $pdo->exec("ALTER TABLE order_operation_idempotency ADD CONSTRAINT chk_order_operation_idempotency_operation CHECK (operation IN ('claim', 'transition', 'release_owner', 'reassign_owner'))");
    $pdo->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.permission_id=rp.permission_id WHERE p.permission_key='production.manage_authority'");
    $pdo->exec("DELETE FROM permissions WHERE permission_key='production.manage_authority'");
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='018_production_authority.sql'");
}

/** Restore a pre-017 disposable test fixture (no production documents). This is NOT a deployment/rollback mechanism. */
function restorePreDocumentsTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    restorePreAuthorityTestSchema($pdo);
    if(!$pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='017_production_documents.sql'")->fetchColumn()) return;
    $pdo->exec('ALTER TABLE operational_orders DROP FOREIGN KEY fk_operational_orders_active_document');
    $pdo->exec('ALTER TABLE production_document_revisions DROP FOREIGN KEY fk_production_document_revisions_request');
    foreach(['production_document_blocks','production_document_events','production_document_prints','production_document_revision_requests','production_document_revisions'] as $table) $pdo->exec('DROP TABLE '.$table);
    $pdo->exec('ALTER TABLE operational_orders DROP CONSTRAINT chk_operational_orders_document_status');
    $pdo->exec('ALTER TABLE operational_orders DROP INDEX idx_operational_orders_document, DROP COLUMN document_context, DROP COLUMN document_status, DROP COLUMN active_document_revision_uuid, DROP COLUMN document_version');
    $pdo->exec("DELETE FROM responsibility_assignments WHERE responsibility_key='document_revision_backup_approver'");
    $pdo->exec('ALTER TABLE responsibility_assignments DROP CONSTRAINT chk_responsibility_assignments_key');
    $pdo->exec("ALTER TABLE responsibility_assignments ADD CONSTRAINT chk_responsibility_assignments_key CHECK (responsibility_key IN ('tailoring_intake_responsible', 'operations_backup_approver'))");
    $pdo->exec("DELETE FROM live_events WHERE audience IN ('document_approvers','document_requesters')");
    $pdo->exec('ALTER TABLE live_events DROP CONSTRAINT chk_live_events_audience');
    $pdo->exec("ALTER TABLE live_events ADD CONSTRAINT chk_live_events_audience CHECK ((audience = 'employee' AND recipient_employee_uuid IS NOT NULL) OR (audience IN ('approvers','cutting','display') AND recipient_employee_uuid IS NULL))");
    $pdo->exec("DELETE era FROM employee_role_assignments era JOIN roles r ON r.role_id=era.role_id WHERE r.role_key IN ('production-documents-operator','document-revision-approver')");
    $pdo->exec("DELETE rp FROM role_permissions rp JOIN roles r ON r.role_id=rp.role_id WHERE r.role_key IN ('production-documents-operator','document-revision-approver')");
    $pdo->exec("DELETE FROM roles WHERE role_key IN ('production-documents-operator','document-revision-approver')");
    $pdo->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.permission_id=rp.permission_id WHERE p.permission_key LIKE 'production.documents.%'");
    $pdo->exec("DELETE FROM permissions WHERE permission_key LIKE 'production.documents.%'");
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='017_production_documents.sql'");
}

/** Restore a pre-014 disposable test fixture (no production exceptions). This is NOT a deployment/rollback mechanism. */
function restorePreCuttingTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    restorePreDocumentsTestSchema($pdo);
    if ($pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='016_management_analytics.sql'")->fetchColumn()) {
        foreach (['analytics_ownership_intervals','analytics_order_projection','analytics_approval_eligibility','analytics_approval_requests'] as $table) $pdo->exec('DROP TABLE '.$table);
        foreach (['production_quality_events'=>['idx_analytics_quality_period'],'production_exception_decisions'=>['idx_analytics_decisions_period','idx_analytics_decisions_opened'],'cutting_transfers'=>['idx_analytics_transfer_period','idx_analytics_transfer_requested'],'order_activity_events'=>['idx_analytics_activity_work','idx_analytics_activity_period','idx_analytics_activity_timeline']] as $table=>$indexes) foreach ($indexes as $index) $pdo->exec('ALTER TABLE '.$table.' DROP INDEX '.$index);
        $pdo->exec('ALTER TABLE production_exception_policy DROP CONSTRAINT chk_analytics_approval_grace, DROP COLUMN approval_grace_minutes, DROP COLUMN analytics_policy_version');
        $pdo->exec("DELETE era FROM employee_role_assignments era JOIN roles r ON r.role_id=era.role_id WHERE r.role_key='analytics-reader'");
        $pdo->exec("DELETE rp FROM role_permissions rp JOIN roles r ON r.role_id=rp.role_id WHERE r.role_key='analytics-reader'");
        $pdo->exec("DELETE FROM roles WHERE role_key='analytics-reader'");
        $pdo->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.permission_id=rp.permission_id WHERE p.permission_key='analytics.view'");
        $pdo->exec("DELETE FROM permissions WHERE permission_key='analytics.view'");
        $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='016_management_analytics.sql'");
    }
    if($pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='015_cutting_pool.sql'")->fetchColumn()) {
        foreach(['cutting_transfers','cutting_facts','cutting_display_devices','cutting_board_settings'] as $table) $pdo->exec('DROP TABLE '.$table);
        if ($pdo->query("SHOW INDEX FROM order_activity_events WHERE Key_name = 'idx_cutting_completed_today'")->fetch()) $pdo->exec('ALTER TABLE order_activity_events DROP INDEX idx_cutting_completed_today');
        $columns = $pdo->query("SHOW COLUMNS FROM operational_orders LIKE 'source_reported_unavailable_at'")->fetchColumn();
        $pdo->exec('ALTER TABLE operational_orders DROP INDEX idx_cutting_pool, DROP COLUMN cutting_first_claimed_at' . ($columns ? ', DROP COLUMN source_reported_unavailable_at' : ''));
        $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='015_cutting_pool.sql'");
    }
}
function restorePreExceptionsTestSchema(PDO $pdo): void
{
    restorePreCuttingTestSchema($pdo);
    if(!$pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='014_production_exceptions.sql'")->fetchColumn()) return;
    $pdo->exec('ALTER TABLE operational_orders DROP FOREIGN KEY fk_operational_orders_open_exception');
    $pdo->exec('ALTER TABLE operational_orders DROP INDEX uq_operational_orders_open_exception, DROP COLUMN open_exception_uuid');
    foreach(['live_events','production_exception_idempotency','production_quality_events','production_exception_events','production_exception_decisions',
        'production_exception_lines','production_exceptions','production_fault_reasons','production_exception_policy','business_hours',
        'responsibility_assignments','employee_secondary_departments','organization_principals'] as $table) $pdo->exec('DROP TABLE IF EXISTS '.$table);
    $pdo->exec('ALTER TABLE order_activity_events DROP CONSTRAINT chk_order_activity_action');
    $pdo->exec("DELETE FROM order_activity_events WHERE action LIKE 'fault\\_%'");
    $pdo->exec("ALTER TABLE order_activity_events ADD CONSTRAINT chk_order_activity_action CHECK(action IN ('claimed','stage_completed','production_completed','owner_released','owner_reassigned','production_submitted'))");
    $pdo->exec("DELETE era FROM employee_role_assignments era JOIN roles r ON r.role_id=era.role_id WHERE r.role_key='operations-manager'");
    $pdo->exec("DELETE rp FROM role_permissions rp JOIN roles r ON r.role_id=rp.role_id WHERE r.role_key='operations-manager'");
    $pdo->exec("DELETE FROM roles WHERE role_key='operations-manager'");
    $pdo->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.permission_id=rp.permission_id WHERE p.permission_key IN ('orders.report_fault','orders.acknowledge_fault','production.exceptions.approve','orders.lookup_exact')");
    $pdo->exec("DELETE FROM permissions WHERE permission_key IN ('orders.report_fault','orders.acknowledge_fault','production.exceptions.approve','orders.lookup_exact')");
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='014_production_exceptions.sql'");
}

/** Restore a pre-013 disposable test fixture (no project workspace). This is NOT a deployment/rollback mechanism. */
function restorePreProjectsTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    restorePreExceptionsTestSchema($pdo);
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
