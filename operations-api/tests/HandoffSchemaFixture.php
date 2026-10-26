<?php
declare(strict_types=1);
/** Restore a pre-041 disposable test fixture (no SmartBill invoicing tables). This is NOT a deployment/rollback mechanism. */
function restorePreSmartBillInvoicingTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    if(!$pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='041_smartbill_invoicing.sql'")->fetchColumn()) return;
    foreach(['order_invoice_qr_checks','order_shop_assignments','order_invoice_actions','order_invoice_reads','employee_invoice_scopes'] as $table) $pdo->exec('DROP TABLE IF EXISTS '.$table);
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='041_smartbill_invoicing.sql'");
}

/** Restore a pre-039 disposable test fixture (no Confirmare finance). This is NOT a deployment/rollback mechanism. */
function restorePreConfirmareFinanceTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    restorePreSmartBillInvoicingTestSchema($pdo);
    if(!$pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='039_confirmare_finance.sql'")->fetchColumn()) return;
    $pdo->exec('DROP TABLE IF EXISTS order_finance_actions');
    $pdo->exec('DROP TABLE IF EXISTS order_finance_reads');
    $pdo->exec('DROP TABLE IF EXISTS employee_finance_scopes');
    $pdo->exec("UPDATE operational_orders SET source_financial_attention = NULL WHERE source_financial_attention NOT IN ('additional_payment_due', 'refund_due') OR source_financial_state = 'cleared'");
    $pdo->exec("ALTER TABLE operational_orders DROP CONSTRAINT chk_operational_orders_finance_attention, ADD CONSTRAINT chk_operational_orders_financial_attention CHECK (source_financial_attention IS NULL OR (source_financial_attention IN ('additional_payment_due', 'refund_due') AND source_financial_state IN ('waiting', 'partial', 'unknown')))");
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='039_confirmare_finance.sql'");
}

/** Restore a pre-038 disposable test fixture (no Confirmare source access). This is NOT a deployment/rollback mechanism. */
function restorePreConfirmareSourceAccessTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    restorePreConfirmareFinanceTestSchema($pdo);
    if(!$pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='038_confirmare_source_access.sql'")->fetchColumn()) return;
    $pdo->exec('DROP TABLE IF EXISTS order_confirmation_source_edits');
    $pdo->exec('DROP TABLE IF EXISTS order_confirmation_source_reads');
    $pdo->exec('ALTER TABLE operational_orders DROP CONSTRAINT chk_operational_orders_financial_attention, DROP CONSTRAINT chk_operational_orders_contact_phone, DROP COLUMN source_financial_attention, DROP COLUMN source_contact_phone');
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='038_confirmare_source_access.sql'");
}

/** Restore a pre-037 disposable test fixture (no Confirmare contact attempts). This is NOT a deployment/rollback mechanism. */
function restorePreConfirmareContactsTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    restorePreConfirmareSourceAccessTestSchema($pdo);
    if(!$pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='037_confirmare_contacts.sql'")->fetchColumn()) return;
    $pdo->exec('DROP TABLE IF EXISTS order_confirmation_contacts');
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='037_confirmare_contacts.sql'");
}

/** Restore a pre-036 disposable test fixture (no Confirmare operations). This is NOT a deployment/rollback mechanism. */
function restorePreConfirmareOperationsTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    restorePreConfirmareContactsTestSchema($pdo);
    if(!$pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='036_confirmare_operations.sql'")->fetchColumn()) return;
    // The four 036 tables stay (identity reads such as the employee view use the scope table at every schema level the
    // suites restore; CREATE TABLE IF NOT EXISTS keeps a re-run idempotent). Only the ALTER of 035's table is undone.
    $pdo->exec('DELETE FROM order_confirmation_idempotency');
    $pdo->exec('ALTER TABLE order_confirmations DROP CONSTRAINT chk_order_confirmations_cleared, DROP FOREIGN KEY fk_order_confirmations_caller, DROP CONSTRAINT chk_order_confirmations_reason, DROP CONSTRAINT chk_order_confirmations_assigned_pending, DROP CONSTRAINT chk_order_confirmations_assignment,
        DROP FOREIGN KEY fk_order_confirmations_assignee, DROP KEY idx_order_confirmations_assignee, DROP COLUMN cleared_at, DROP COLUMN production_cleared, DROP COLUMN approved_review_fingerprint, DROP COLUMN confirmed_line_ids,
        DROP COLUMN call_confirmed_by_employee_uuid, DROP COLUMN call_confirmed_at, DROP COLUMN decision_reason_code, DROP COLUMN assignment_kind, DROP COLUMN assigned_at, DROP COLUMN assigned_employee_uuid');
    $pdo->exec('ALTER TABLE operational_orders DROP CONSTRAINT chk_operational_orders_financial_complete, DROP CONSTRAINT chk_operational_orders_financial_pricing, DROP CONSTRAINT chk_operational_orders_financial_basis,
        DROP CONSTRAINT chk_operational_orders_financial_state, DROP COLUMN source_financial_pricing, DROP COLUMN source_financial_basis, DROP COLUMN source_financial_state');
    $pdo->exec('ALTER TABLE operational_orders DROP CONSTRAINT chk_operational_orders_pricing_fingerprint, DROP COLUMN source_due_date, DROP COLUMN source_pricing_fingerprint');
    $pdo->exec('DELETE FROM confirmare_alerts');
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='036_confirmare_operations.sql'");
}

/** Restore a pre-035 disposable test fixture (no Confirmare workflow cohort). This is NOT a deployment/rollback mechanism. */
function restorePreWorkflowCohortTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    restorePreConfirmareOperationsTestSchema($pdo);
    if(!$pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='035_workflow_cohort.sql'")->fetchColumn()) return;
    foreach(['order_confirmations','workflow_cohort_arming','workflow_cohort_activations'] as $table) $pdo->exec("DROP TABLE IF EXISTS {$table}");
    $pdo->exec('ALTER TABLE operational_orders DROP CONSTRAINT chk_operational_orders_workflow_cohort_complete, DROP CONSTRAINT chk_operational_orders_workflow_cohort,
        DROP KEY idx_operational_orders_cohort, DROP COLUMN source_submission_evidence, DROP COLUMN source_submitted_at, DROP COLUMN workflow_cohort_at,
        DROP COLUMN workflow_cohort_reason, DROP COLUMN workflow_cohort');
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='035_workflow_cohort.sql'");
}

/** Restore a pre-034 disposable test fixture (no cancellation synchronization). This is NOT a deployment/rollback mechanism. */
function restorePreCancellationSyncTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    restorePreWorkflowCohortTestSchema($pdo);
    if(!$pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='034_order_cancellation_sync.sql'")->fetchColumn()) return;
    foreach(['order_cancellation_discrepancies','order_cancellation_sync_attempts','order_cancellation_sync_operations'] as $table) $pdo->exec("DROP TABLE IF EXISTS {$table}");
    $flags = "'cancellation_sync_trendyol','cancellation_sync_trendhome','cancellation_sync_outletperdele'";
    if($pdo->query("SHOW TABLES LIKE 'production_feature_flag_events'")->fetchColumn()!==false) $pdo->exec("DELETE FROM production_feature_flag_events WHERE feature_key IN ({$flags})");
    $pdo->exec("DELETE FROM production_feature_flags WHERE flag_key IN ({$flags})");
    $pdo->exec("DELETE rp FROM role_permissions rp INNER JOIN permissions p ON p.permission_id = rp.permission_id WHERE p.permission_key = 'production.cancellation.sync'");
    $pdo->exec("DELETE FROM permissions WHERE permission_key = 'production.cancellation.sync'");
    $pdo->exec('ALTER TABLE order_cancellation_controls DROP CONSTRAINT chk_order_cancellation_controls_generation');
    $pdo->exec('ALTER TABLE order_cancellation_controls DROP COLUMN hold_generation');
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='034_order_cancellation_sync.sql'");
}

/** Restore a pre-033 disposable test fixture (no cancellation control). This is NOT a deployment/rollback mechanism. */
function restorePreCancellationControlTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    restorePreCancellationSyncTestSchema($pdo);
    if(!$pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='033_order_cancellation_control.sql'")->fetchColumn()) return;
    foreach(['order_cancellation_events','order_cancellation_controls'] as $table) $pdo->exec("DROP TABLE IF EXISTS {$table}");
    $pdo->exec("DELETE rp FROM role_permissions rp INNER JOIN permissions p ON p.permission_id = rp.permission_id WHERE p.permission_key IN ('production.cancellation.manage','production.cancellation.release')");
    $pdo->exec("DELETE FROM permissions WHERE permission_key IN ('production.cancellation.manage','production.cancellation.release')");
    $pdo->exec('ALTER TABLE operational_orders DROP CONSTRAINT chk_operational_orders_cancellation');
    $pdo->exec('ALTER TABLE operational_orders DROP INDEX idx_operational_orders_cancellation, DROP COLUMN production_stopped_at, DROP COLUMN cancellation_state');
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='033_order_cancellation_control.sql'");
}

/** Restore a pre-029 disposable test fixture (no manufacturing manifest). This is NOT a deployment/rollback mechanism. */
function restorePreManifestTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    restorePreCancellationControlTestSchema($pdo);
    if(!$pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='029_production_manifest_routing.sql'")->fetchColumn()) return;
    \Arasya\Operations\Tests\OperationsTestSupport::clearPieces($pdo);
    foreach(['production_assisted_checkpoint_events','production_assisted_checkpoints','production_route_reconciliation_stages','production_route_reconciliations','production_unit_verifications','production_feature_flag_events','production_pilot_cohort','production_exception_links','production_material_records','production_custody_events','production_unit_custody','production_locations',
        'production_unit_replacements','production_unit_cycles','production_unit_stage_log','production_route_decisions','production_plan_pieces','production_line_plan_heads','production_line_plans'] as $table) $pdo->exec("DROP TABLE IF EXISTS {$table}");
    $pdo->exec("DELETE FROM production_feature_flags WHERE flag_key IN ('piece_routing','perdea_assisted_checkpoints')");
    // MySQL refuses to tighten a column used by a foreign key while the checks are on (error 1832); test fixture only.
    $pdo->exec("DELETE FROM production_tracks WHERE origin_exception_uuid IS NULL");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('ALTER TABLE production_tracks MODIFY origin_exception_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='030_production_route_reconciliation.sql'");
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='031_production_assisted_checkpoints.sql'");
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='029_production_manifest_routing.sql'");
}

/** Restore a pre-028 disposable test fixture (no production work units). This is NOT a deployment/rollback mechanism. */
function restorePreWorkUnitsTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    restorePreManifestTestSchema($pdo);
    if(!$pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='028_production_work_units.sql'")->fetchColumn()) return;
    \Arasya\Operations\Tests\OperationsTestSupport::clearPieces($pdo);
    foreach(['production_track_idempotency','production_unit_labels','production_exception_units','production_exception_scopes','production_track_events','production_work_units','production_tracks','production_feature_flags'] as $table) $pdo->exec("DROP TABLE IF EXISTS {$table}");
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='028_production_work_units.sql'");
}

/** Restore a pre-026 disposable test fixture (no Trendyol product identity). This is NOT a deployment/rollback mechanism. */
function restorePreTrendyolProductIdentityTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    restorePreWorkUnitsTestSchema($pdo);
    if(!$pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='026_trendyol_product_identity.sql'")->fetchColumn()) return;
    foreach(['trendyol_product_title_events','trendyol_product_titles'] as $table) $pdo->exec("DROP TABLE IF EXISTS {$table}");
    $pdo->exec('ALTER TABLE trendyol_package_lines DROP COLUMN product_code, DROP COLUMN prepared_title');
    $pdo->exec("DELETE FROM trendyol_intake_events WHERE action = 'production_identity_corrected'");
    $pdo->exec('ALTER TABLE trendyol_intake_events DROP CONSTRAINT chk_trendyol_intake_events_action');
    $pdo->exec("ALTER TABLE trendyol_intake_events ADD CONSTRAINT chk_trendyol_intake_events_action CHECK (action IN ('intake_activated','intake_paused','intake_resumed','received','marketplace_updated','marketplace_lines_changed','marketplace_cancelled','changed_after_release','line_prepared','dismissed','reopened','released'))");
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='026_trendyol_product_identity.sql'");
}

/** Restore a pre-025 disposable test fixture (no source payment gate). This is NOT a deployment/rollback mechanism. */
function restorePrePaymentGateTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    restorePreTrendyolProductIdentityTestSchema($pdo);
    if(!$pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='025_source_payment_gate.sql'")->fetchColumn()) return;
    foreach(['chk_operational_orders_payment_method','chk_operational_orders_advance_status','chk_operational_orders_payment_complete','chk_operational_orders_payment_online','chk_operational_orders_advance_confirmed'] as $check) $pdo->exec("ALTER TABLE operational_orders DROP CONSTRAINT {$check}");
    $pdo->exec('ALTER TABLE operational_orders DROP COLUMN payment_method, DROP COLUMN advance_status, DROP COLUMN advance_revision, DROP COLUMN advance_confirmed_at, DROP COLUMN payment_synced_at, DROP COLUMN payment_reversed_at');
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='025_source_payment_gate.sql'");
}

/** Restore a pre-024 disposable test fixture (no tailoring quality supervision). This is NOT a deployment/rollback mechanism. */
function restorePreTailoringSupervisionTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    restorePrePaymentGateTestSchema($pdo);
    if(!$pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name='024_tailoring_quality_supervision.sql'")->fetchColumn()) return;
    $ids=$pdo->query("SELECT exception_uuid FROM production_exceptions WHERE exception_type='tailoring_defect' OR reason_key IN ('cut-meterage','cut-color','fabric-defect') OR confirmed_reason_key IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    if($ids!==[]){
        $list=implode(',',array_map([$pdo,'quote'],$ids));
        $pdo->exec("UPDATE operational_orders SET open_exception_uuid=NULL WHERE open_exception_uuid IN ({$list})");
        foreach(['production_quality_events','production_exception_events','production_exception_lines'] as $table) $pdo->exec("DELETE FROM {$table} WHERE exception_uuid IN ({$list})");
        $pdo->exec("UPDATE production_exception_decisions SET previous_decision_uuid=NULL WHERE exception_uuid IN ({$list})");
        $pdo->exec("DELETE FROM production_exception_decisions WHERE exception_uuid IN ({$list})");
        $pdo->exec("DELETE FROM production_exceptions WHERE exception_uuid IN ({$list})");
    }
    $pdo->exec('DELETE FROM production_quality_events WHERE rework_cycle IS NULL OR meters = 0 OR unmeasured_line_count > 0');
    $pdo->exec('ALTER TABLE production_quality_events DROP CONSTRAINT chk_production_quality_events_meters');
    $pdo->exec('ALTER TABLE production_quality_events DROP COLUMN unmeasured_line_count');
    $pdo->exec('ALTER TABLE production_quality_events MODIFY rework_cycle INT UNSIGNED NOT NULL');
    $pdo->exec('ALTER TABLE production_quality_events ADD CONSTRAINT chk_production_quality_events_meters CHECK (meters > 0 AND line_count > 0 AND rework_cycle > 0)');
    $pdo->exec('ALTER TABLE production_exception_lines DROP CONSTRAINT chk_production_exception_lines_meters');
    $pdo->exec('ALTER TABLE production_exception_lines MODIFY meters_snapshot DECIMAL(12,3) NOT NULL');
    $pdo->exec('ALTER TABLE production_exception_lines ADD CONSTRAINT chk_production_exception_lines_meters CHECK (meters_snapshot > 0)');
    $pdo->exec('ALTER TABLE production_exceptions DROP CONSTRAINT chk_production_exceptions_meters');
    $pdo->exec('ALTER TABLE production_exceptions ADD CONSTRAINT chk_production_exceptions_meters CHECK (fault_meters > 0)');
    $pdo->exec('ALTER TABLE order_activity_events DROP CONSTRAINT chk_order_activity_action');
    $pdo->exec("DELETE FROM order_activity_events WHERE action IN ('defect_custody_taken','defect_dismissed','defect_restored')");
    $pdo->exec("ALTER TABLE order_activity_events ADD CONSTRAINT chk_order_activity_action CHECK (action IN ('claimed', 'stage_completed', 'production_completed', 'owner_released', 'owner_reassigned', 'production_submitted', 'fault_reported', 'fault_rejected', 'fault_rereview_requested', 'fault_returned', 'fault_cancelled', 'authority_taken_over', 'authority_released'))");
    $pdo->exec('ALTER TABLE production_exception_events DROP CONSTRAINT chk_production_exception_events_action');
    $pdo->exec("ALTER TABLE production_exception_events ADD CONSTRAINT chk_production_exception_events_action CHECK (action IN ('reported', 'acknowledged', 'approval_requested', 'approved', 'rejected', 'rereview_requested', 'cancelled'))");
    $pdo->exec('ALTER TABLE production_exceptions DROP CONSTRAINT chk_production_exceptions_status');
    $pdo->exec("ALTER TABLE production_exceptions ADD CONSTRAINT chk_production_exceptions_status CHECK (status IN ('awaiting_acknowledgment', 'awaiting_approval', 'approved', 'rejected', 'cancelled'))");
    $pdo->exec('ALTER TABLE production_exceptions DROP CONSTRAINT chk_production_exceptions_type');
    $pdo->exec("ALTER TABLE production_exceptions ADD CONSTRAINT chk_production_exceptions_type CHECK (exception_type IN ('cutting_fault'))");
    foreach(['chk_production_exceptions_position','chk_production_exceptions_attribution','chk_production_exceptions_supervisor_people','chk_production_exceptions_receiver_people','chk_production_exceptions_tailoring','chk_production_exceptions_restore','chk_production_exceptions_unmeasured'] as $check) $pdo->exec("ALTER TABLE production_exceptions DROP CONSTRAINT {$check}");
    foreach(['fk_production_exceptions_detected_owner','fk_production_exceptions_supervisor','fk_production_exceptions_receiver','fk_production_exceptions_custodian','fk_production_exceptions_restore_recipient','fk_production_exceptions_confirmed_reason'] as $fk) $pdo->exec("ALTER TABLE production_exceptions DROP FOREIGN KEY {$fk}");
    $pdo->exec('ALTER TABLE production_exceptions DROP INDEX idx_production_exceptions_supervisor, DROP INDEX idx_production_exceptions_receiver, DROP INDEX idx_production_exceptions_type_status, DROP INDEX idx_production_exceptions_restore_recipient');
    $pdo->exec('ALTER TABLE production_exceptions DROP COLUMN detected_owner_employee_uuid, DROP COLUMN supervisor_employee_uuid, DROP COLUMN supervisor_reviewed_at, DROP COLUMN supervisor_comment, DROP COLUMN confirmed_reason_key, DROP COLUMN confirmed_reason_label_snapshot, DROP COLUMN return_requested_at, DROP COLUMN receiver_employee_uuid, DROP COLUMN physical_custodian_employee_uuid, DROP COLUMN restore_recipient_employee_uuid, DROP COLUMN restore_requested_at, DROP COLUMN restore_received_at, DROP COLUMN unmeasured_line_count, DROP COLUMN responsibility_position, DROP COLUMN attribution');
    // Some MySQL/MariaDB versions keep the FK-supporting indexes after the FK is dropped; drop leftovers.
    foreach($pdo->query("SELECT DISTINCT index_name FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='production_exceptions' AND index_name LIKE 'fk_production_exceptions_%' AND index_name IN ('fk_production_exceptions_detected_owner','fk_production_exceptions_custodian','fk_production_exceptions_confirmed_reason')")->fetchAll(PDO::FETCH_COLUMN) as $index) $pdo->exec("ALTER TABLE production_exceptions DROP INDEX {$index}");
    $pdo->exec("DELETE FROM production_fault_reasons WHERE reason_key IN ('cut-meterage','cut-color','fabric-defect')");
    $pdo->exec('ALTER TABLE production_fault_reasons DROP CONSTRAINT chk_production_fault_reasons_attributable');
    $pdo->exec('ALTER TABLE production_fault_reasons DROP COLUMN cutting_attributable');
    $pdo->exec("DELETE FROM responsibility_assignments WHERE responsibility_key='tailoring_quality_supervisor'");
    $pdo->exec('ALTER TABLE responsibility_assignments DROP CONSTRAINT chk_responsibility_assignments_key');
    $pdo->exec("ALTER TABLE responsibility_assignments ADD CONSTRAINT chk_responsibility_assignments_key CHECK (responsibility_key IN ('tailoring_intake_responsible', 'operations_backup_approver', 'document_revision_backup_approver'))");
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name='024_tailoring_quality_supervision.sql'");
}

/** Restore a pre-022 disposable test fixture (no Trendyol intake). This is NOT a deployment/rollback mechanism. */
function restorePreTrendyolIntakeTestSchema(PDO $pdo): void
{
    if(!str_contains(strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'test')) throw new RuntimeException('Test database required.');
    restorePreTailoringSupervisionTestSchema($pdo);
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
