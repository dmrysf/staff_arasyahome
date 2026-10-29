<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = glob($root . '/database/migrations/*.sql') ?: [];
sort($files, SORT_STRING);
if ($files === []) {
    throw new RuntimeException('No database migrations were found.');
}
$expected = ['departments', 'roles', 'permissions', 'role_permissions', 'employees', 'employee_stage_access', 'auth_sessions', 'auth_login_attempts', 'auth_rate_limit_buckets', 'auth_audit_events', 'production_workflows', 'production_stages', 'order_sources', 'operational_orders', 'operational_order_items', 'employee_order_relations', 'order_projection_receipts', 'order_qr_references', 'order_activity_events', 'order_operation_idempotency', 'api_rate_limit_buckets', 'applications', 'employee_application_access', 'employee_role_assignments', 'system_root_identity', 'iam_audit_events', 'b2b_company_number_sequence', 'b2b_companies', 'b2b_company_contacts', 'b2b_company_addresses', 'b2b_company_activity_events', 'b2b_company_idempotency', 'b2b_account_movement_sequence', 'b2b_account_movements', 'b2b_account_allocations', 'b2b_account_allocation_releases', 'b2b_account_activity_events', 'b2b_account_idempotency'];
$schema = implode("\n", array_map(static fn (string $path): string => (string) file_get_contents($path), $files));
$expected[]='b2b_production_handoffs';
$expected[]='employee_stage_source_scopes';
array_push($expected,'b2b_project_number_sequence','b2b_projects','b2b_project_zones','b2b_project_rooms','b2b_project_openings','b2b_project_treatments','b2b_project_orders','b2b_project_order_lines','b2b_project_activity_events','b2b_project_idempotency');
foreach ($expected as $table) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS\s+' . preg_quote($table, '/') . '\b/i', $schema) !== 1) {
        throw new RuntimeException("Migration is missing table: {$table}");
    }
}
if (!str_contains($schema, 'token_hash BINARY(32)')) {
    throw new RuntimeException('Session token hashes must use fixed 32-byte storage.');
}
if (!str_contains($schema, 'UNIQUE KEY uq_production_stages_workflow_ordinal') || !str_contains($schema, 'CHECK (ordinal > 0)')) {
    throw new RuntimeException('Production workflow ordering constraints are missing.');
}
foreach (['UNIQUE KEY uq_order_activity_idempotency', 'PRIMARY KEY (employee_uuid, idempotency_key)', 'ADD COLUMN production_version', 'CHECK (production_version_after > production_version_before)'] as $required) {
    if (!str_contains($schema, $required)) {
        throw new RuntimeException("Staff operations integrity constraint is missing: {$required}");
    }
}
foreach (['PRIMARY KEY (singleton_id)', 'CHECK (singleton_id = 1)', 'UNIQUE KEY uq_system_root_identity_employee'] as $required) {
    if (!str_contains($schema, $required)) {
        throw new RuntimeException("Root identity invariant is missing: {$required}");
    }
}
foreach (['UNIQUE KEY uq_b2b_companies_code', 'UNIQUE KEY uq_b2b_companies_tax_identifier (country_code, tax_identifier_normalized)', 'UNIQUE KEY uq_b2b_company_contacts_primary', 'UNIQUE KEY uq_b2b_company_addresses_primary (primary_company_uuid, primary_address_type)', 'company_number BIGINT UNSIGNED NOT NULL AUTO_INCREMENT'] as $required) {
    if (!str_contains($schema, $required)) {
        throw new RuntimeException("B2B company integrity constraint is missing: {$required}");
    }
}
// B2B company records are never deleted through foreign keys: future orders and accounts must keep referencing them.
$b2bSchema = (string) file_get_contents($root . '/database/migrations/009_b2b_companies.sql');
if (preg_match('/ON DELETE (CASCADE|SET NULL)/i', $b2bSchema) === 1) {
    throw new RuntimeException('B2B company tables must not cascade or null out on delete.');
}
// Current account rows are insert-only evidence: no cascades, one receivable per order, one reversal per movement.
$handoffSchema=(string)file_get_contents($root.'/database/migrations/012_b2b_production_handoff.sql');
foreach(['PRIMARY KEY(b2b_order_uuid)','UNIQUE KEY uq_b2b_handoff_operational','FOREIGN KEY(operational_order_uuid,source_key,b2b_order_uuid)',"CHECK(source_key='b2b')"] as $constraint)
    if(!str_contains($handoffSchema,$constraint)) throw new RuntimeException('B2B handoff identity constraint missing.');
if(preg_match('/ON (DELETE|UPDATE) (CASCADE|SET NULL)|INSERT.*role_permissions/is',$handoffSchema)) throw new RuntimeException('Handoff must retain evidence and grant no roles.');
// Project workspaces never cascade into commercial, financial or production records and grant no roles.
$projectSchema=(string)file_get_contents($root.'/database/migrations/013_b2b_projects.sql');
if(preg_match('/ON (DELETE|UPDATE) (CASCADE|SET NULL)|INSERT.*role_permissions|ALTER TABLE b2b_orders|ALTER TABLE operational_|b2b_account_/is',$projectSchema)) throw new RuntimeException('Project workspace must stay additive and isolated.');
foreach(['PRIMARY KEY(order_uuid), KEY idx_b2b_project_orders_project','UNIQUE KEY uq_b2b_project_order_treatment(order_uuid,treatment_uuid)'] as $constraint)
    if(!str_contains($projectSchema,$constraint)) throw new RuntimeException('Project order trace constraint missing.');
$accountSchema = (string) file_get_contents($root . '/database/migrations/011_b2b_current_account.sql');
if (preg_match('/ON (DELETE|UPDATE) (CASCADE|SET NULL)/i', $accountSchema) === 1) {
    throw new RuntimeException('B2B current account tables must not cascade.');
}
foreach (['UNIQUE KEY uq_b2b_account_receivable_order(receivable_order_uuid)', 'UNIQUE KEY uq_b2b_account_reversed_movement(reversed_movement_uuid)', 'amount DECIMAL(16,2) NOT NULL', 'CONSTRAINT chk_b2b_account_amount CHECK(amount>0)'] as $required) {
    if (!str_contains($accountSchema, $required)) {
        throw new RuntimeException("B2B current account invariant is missing: {$required}");
    }
}
// Production exceptions: additive only, append-only history, one decision per attempt, quality facts once.
$exceptionSchema = (string) file_get_contents($root . '/database/migrations/014_production_exceptions.sql');
foreach (['organization_principals', 'employee_secondary_departments', 'responsibility_assignments', 'business_hours', 'production_exception_policy', 'production_fault_reasons', 'production_exceptions', 'production_exception_lines', 'production_exception_decisions', 'production_exception_events', 'production_quality_events', 'production_exception_idempotency', 'live_events'] as $table) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS\s+' . $table . '\b/', $exceptionSchema) !== 1) {
        throw new RuntimeException("Migration 014 is missing table: {$table}");
    }
}
foreach (['UNIQUE KEY uq_production_exceptions_rework_cycle (order_uuid, rework_cycle)', 'UNIQUE KEY uq_production_exception_decisions_attempt (exception_uuid, attempt_number)', 'UNIQUE KEY uq_production_quality_events_once (exception_uuid, event_type)', 'UNIQUE KEY uq_production_exception_events_version (exception_uuid, exception_version_after)', 'UNIQUE KEY uq_operational_orders_open_exception (open_exception_uuid)', "CHECK (approval_mode IN ('blocking'))", "'production_submitted',"] as $required) {
    if (!str_contains($exceptionSchema, $required)) {
        throw new RuntimeException("Production exception invariant is missing: {$required}");
    }
}
if (preg_match('/ON (DELETE|UPDATE) (CASCADE|SET NULL)|DROP TABLE|DELETE FROM|TRUNCATE|UPDATE operational_orders|UPDATE order_activity_events/i', $exceptionSchema) === 1) {
    throw new RuntimeException('Migration 014 must stay additive and must not rewrite history.');
}
if (preg_match_all('/INSERT IGNORE INTO role_permissions[\s\S]*?WHERE r\.role_key = \'([a-z-]+)\'/', $exceptionSchema, $grants) > 0 && array_unique($grants[1]) !== ['operations-manager']) {
    throw new RuntimeException('Migration 014 may grant permissions only to the new operations-manager template.');
}
// Production documents: additive, append-only history, one active revision and one open request per order.
$documentSchema = (string) file_get_contents($root . '/database/migrations/017_production_documents.sql');
foreach (['production_document_revisions', 'production_document_revision_requests', 'production_document_prints', 'production_document_events', 'production_document_blocks'] as $table) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS\s+' . $table . '\b/', $documentSchema) !== 1) {
        throw new RuntimeException("Migration 017 is missing table: {$table}");
    }
}
foreach (['UNIQUE KEY uq_production_document_revisions_active (active_order_uuid)', 'UNIQUE KEY uq_production_document_revisions_number (order_uuid, revision_number)', 'UNIQUE KEY uq_production_document_revisions_qr (qr_reference)', 'UNIQUE KEY uq_production_document_requests_open (open_order_uuid)', 'CHECK (status <> \'rejected\' OR decision_comment IS NOT NULL)'] as $required) {
    if (!str_contains($documentSchema, $required)) {
        throw new RuntimeException("Production document invariant is missing: {$required}");
    }
}
if (preg_match('/ON (DELETE|UPDATE) (CASCADE|SET NULL)|DROP TABLE|DELETE FROM|TRUNCATE|UPDATE operational_orders|UPDATE order_qr_references|UPDATE order_activity_events/i', $documentSchema) === 1) {
    throw new RuntimeException('Migration 017 must stay additive and must not rewrite history.');
}
if (preg_match_all('/INSERT IGNORE INTO role_permissions[\s\S]*?WHERE r\.role_key = \'([a-z-]+)\'/', $documentSchema, $documentGrants) > 0 && array_values(array_unique($documentGrants[1])) !== ['production-documents-operator', 'document-revision-approver']) {
    throw new RuntimeException('Migration 017 may grant permissions only to its two new templates.');
}
if (str_contains($documentSchema, 'employee_role_assignments')) {
    throw new RuntimeException('Migration 017 must not assign roles to employees.');
}
// Per-piece rework: purely additive and re-runnable (no ALTER of an existing table), append-only track history,
// one identity per physical piece, one open exception per track, created disabled.
$unitSchema = (string) file_get_contents($root . '/database/migrations/028_production_work_units.sql');
foreach (['production_feature_flags', 'production_tracks', 'production_work_units', 'production_exception_scopes', 'production_exception_units', 'production_track_events', 'production_track_idempotency', 'production_unit_labels'] as $table) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS\s+' . $table . '\b/', $unitSchema) !== 1) {
        throw new RuntimeException("Migration 028 is missing table: {$table}");
    }
}
foreach (['UNIQUE KEY uq_production_work_units_identity (item_uuid, unit_index)', 'UNIQUE KEY uq_production_tracks_open_exception (open_exception_uuid)', 'UNIQUE KEY uq_production_track_events_version (track_uuid, version_after)', 'UNIQUE KEY uq_production_track_events_idempotency (employee_uuid, idempotency_key)', "VALUES ('partial_rework', 0,"] as $required) {
    if (!str_contains($unitSchema, $required)) {
        throw new RuntimeException("Per-piece rework invariant is missing: {$required}");
    }
}
if (preg_match('/ON (DELETE|UPDATE) (CASCADE|SET NULL)|ALTER TABLE|DROP |DELETE FROM|TRUNCATE|(?<!ON )UPDATE |role_permissions|employee_role_assignments/i', preg_replace('/^--.*$/m', '', $unitSchema) ?? '') === 1) {
    throw new RuntimeException('Migration 028 must stay additive, re-runnable and must not grant roles.');
}
// Manufacturing manifest and routes: additive and re-runnable; the only ALTER relaxes one column of a 028 table; one
// active plan head per line, one replacement per original, one material record per piece/kind/cycle; created disabled.
$manifestSchema = (string) file_get_contents($root . '/database/migrations/029_production_manifest_routing.sql');
foreach (['production_unit_verifications', 'production_line_plans', 'production_line_plan_heads', 'production_plan_pieces', 'production_route_decisions', 'production_unit_stage_log', 'production_unit_cycles', 'production_unit_replacements',
    'production_locations', 'production_unit_custody', 'production_custody_events', 'production_material_records', 'production_exception_links', 'production_pilot_cohort', 'production_feature_flag_events'] as $table) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS\s+' . $table . '\b/', $manifestSchema) !== 1) {
        throw new RuntimeException("Migration 029 is missing table: {$table}");
    }
}
foreach (['UNIQUE KEY uq_production_line_plans_revision (item_uuid, revision)', 'UNIQUE KEY uq_production_unit_replacements_original (original_unit_uuid)', 'UNIQUE KEY uq_production_material_records_once (dedupe_key)',
    'UNIQUE KEY uq_production_unit_stage_log_once (unit_uuid, rework_cycle, stage_id)', "CHECK (stock_effect = 'none' AND unit = 'm')", "VALUES ('piece_routing', 0,"] as $required) {
    if (!str_contains($manifestSchema, $required)) {
        throw new RuntimeException("Manufacturing manifest invariant is missing: {$required}");
    }
}
$manifestStatements = preg_replace(['/^--.*$/m', '/ON (UPDATE|DELETE) RESTRICT/'], '', $manifestSchema) ?? '';
if (preg_match('/ON (DELETE|UPDATE) (CASCADE|SET NULL)|DROP |DELETE FROM|TRUNCATE|\bUPDATE |role_permissions|employee_role_assignments/i', $manifestStatements) === 1
    || preg_match_all('/ALTER TABLE ([a-z_]+)/', $manifestStatements, $manifestAlters) !== 1 || $manifestAlters[1] !== ['production_tracks']) {
    throw new RuntimeException('Migration 029 must stay additive, re-runnable and must not grant roles.');
}
// Cancellation control (033): additive, grants nothing, never rewrites an order row; the only ALTER adds the two stop
// projection columns to operational_orders; one control row per order and one audit row per source event and type.
$cancellationSchema = (string) file_get_contents($root . '/database/migrations/033_order_cancellation_control.sql');
foreach (['order_cancellation_controls', 'order_cancellation_events'] as $table) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS\s+' . $table . '\b/', $cancellationSchema) !== 1) {
        throw new RuntimeException("Migration 033 is missing table: {$table}");
    }
}
foreach (['PRIMARY KEY (order_uuid)', 'UNIQUE KEY uq_order_cancellation_events_source (order_uuid, event_type, source_event_ref)', 'CONSTRAINT chk_operational_orders_cancellation CHECK',
    "CHECK (hold_status IN ('held', 'released'))", "('production.cancellation.manage'", "('production.cancellation.release'"] as $required) {
    if (!str_contains($cancellationSchema, $required)) {
        throw new RuntimeException("Cancellation control invariant is missing: {$required}");
    }
}
$cancellationStatements = preg_replace(['/^--.*$/m', '/ON (UPDATE|DELETE) RESTRICT/', '/ON DUPLICATE KEY UPDATE/'], '', $cancellationSchema) ?? '';
if (preg_match('/ON (DELETE|UPDATE) (CASCADE|SET NULL)|DROP |DELETE FROM|TRUNCATE|\bUPDATE |role_permissions|employee_role_assignments/i', $cancellationStatements) === 1
    || preg_match_all('/ALTER TABLE ([a-z_]+)/', $cancellationStatements, $cancellationAlters) !== 1 || $cancellationAlters[1] !== ['operational_orders']) {
    throw new RuntimeException('Migration 033 must stay additive and must not grant roles or rewrite rows.');
}
// Cancellation synchronization (034): additive, grants nothing, creates every provider flag disabled; the only ALTER adds
// the stop generation to the control rows.
$syncSchema = (string) file_get_contents($root . '/database/migrations/034_order_cancellation_sync.sql');
foreach (['order_cancellation_sync_operations', 'order_cancellation_sync_attempts', 'order_cancellation_discrepancies'] as $table) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS\s+' . $table . '\b/', $syncSchema) !== 1) {
        throw new RuntimeException("Migration 034 is missing table: {$table}");
    }
}
foreach (['UNIQUE KEY uq_order_cancellation_sync_generation (order_uuid, hold_generation)', 'UNIQUE KEY uq_order_cancellation_sync_open (open_order_uuid)',
    "('cancellation_sync_trendyol', 0,", "('cancellation_sync_trendhome', 0,", "('cancellation_sync_outletperdele', 0,", "('production.cancellation.sync'"] as $required) {
    if (!str_contains($syncSchema, $required)) {
        throw new RuntimeException("Migration 034 is missing: {$required}");
    }
}
$syncStatements = preg_replace(['/^--.*$/m', '/ON (UPDATE|DELETE) RESTRICT/', '/ON DUPLICATE KEY UPDATE/'], '', $syncSchema) ?? '';
if (preg_match('/ON (DELETE|UPDATE) (CASCADE|SET NULL)|DROP |DELETE FROM|TRUNCATE|\bUPDATE |role_permissions|employee_role_assignments|, 1, NULL/i', $syncStatements) === 1
    || preg_match_all('/ALTER TABLE ([a-z_]+)/', $syncStatements, $syncAlters) !== 1 || $syncAlters[1] !== ['order_cancellation_controls']) {
    throw new RuntimeException('Migration 034 must stay additive, create its flags disabled and must not grant roles or rewrite rows.');
}
// SmartBill invoicing (041, owner closure 2026-10-25; 040 stays reserved): five new tables only, nothing granted, no row
// written or rewritten, no other table altered, no cascading key; running it again changes nothing.
if (is_file($root . '/database/migrations/040_smartbill_invoicing.sql')) {
    throw new RuntimeException('The SmartBill invoicing schema is migration 041; 040 is reserved.');
}
$invoiceSchema = (string) file_get_contents($root . '/database/migrations/041_smartbill_invoicing.sql');
foreach (['employee_invoice_scopes', 'order_invoice_reads', 'order_invoice_actions', 'order_shop_assignments', 'order_invoice_qr_checks'] as $table) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS\s+' . $table . '\b/', $invoiceSchema) !== 1) {
        throw new RuntimeException("Migration 041 is missing table: {$table}");
    }
}
$invoiceStatements = preg_replace(['/^--.*$/m', '/ON (UPDATE|DELETE) RESTRICT/'], '', $invoiceSchema) ?? '';
if (preg_match('/ON (DELETE|UPDATE) (CASCADE|SET NULL)|DROP |DELETE FROM|TRUNCATE|\bUPDATE |\bINSERT |ALTER TABLE|role_permissions|employee_role_assignments/i', $invoiceStatements) === 1
    || preg_match_all('/CREATE TABLE IF NOT EXISTS/', $invoiceStatements) !== 5 || preg_match('/CREATE TABLE (?!IF NOT EXISTS)/', $invoiceStatements) === 1) {
    throw new RuntimeException('Migration 041 must stay additive (five CREATE TABLE IF NOT EXISTS), grant nothing and write no row.');
}
// Confirmare connector readiness (042, API 2.45.0): one new table only, nothing granted, no row, no other table altered.
$readinessSchema = (string) file_get_contents($root . '/database/migrations/042_confirmare_connector_readiness.sql');
$readinessStatements = preg_replace(['/^--.*$/m', '/ON (UPDATE|DELETE) RESTRICT/'], '', $readinessSchema) ?? '';
if (preg_match('/CREATE TABLE IF NOT EXISTS\s+source_connector_readiness\b/', $readinessSchema) !== 1
    || preg_match('/ON (DELETE|UPDATE) (CASCADE|SET NULL)|DROP |DELETE FROM|TRUNCATE|\bUPDATE |\bINSERT |ALTER TABLE|role_permissions|employee_role_assignments/i', $readinessStatements) === 1
    || preg_match_all('/CREATE TABLE IF NOT EXISTS/', $readinessStatements) !== 1 || preg_match('/CREATE TABLE (?!IF NOT EXISTS)/', $readinessStatements) === 1) {
    throw new RuntimeException('Migration 042 must stay additive (one CREATE TABLE IF NOT EXISTS), grant nothing and write no row.');
}
// The dual-store operating workflow (043, API 2.46.0): six new tables only, nothing granted, no row, no other table altered.
$dualSchema = (string) file_get_contents($root . '/database/migrations/043_confirmare_dual_store.sql');
$dualStatements = preg_replace(['/^--.*$/m', '/ON (UPDATE|DELETE) RESTRICT/'], '', $dualSchema) ?? '';
foreach (['confirmare_proforma_cycles', 'confirmare_cycle_steps', 'confirmare_bank_checks', 'confirmare_reactivations', 'invoice_efactura_tasks', 'order_source_operations'] as $table) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS\s+' . $table . '\b/', $dualSchema) !== 1) {
        throw new RuntimeException("Migration 043 is missing table: {$table}");
    }
}
if (preg_match('/ON (DELETE|UPDATE) (CASCADE|SET NULL)|DROP |DELETE FROM|TRUNCATE|\bUPDATE |\bINSERT |ALTER TABLE|role_permissions|employee_role_assignments/i', $dualStatements) === 1
    || preg_match_all('/CREATE TABLE IF NOT EXISTS/', $dualStatements) !== 6 || preg_match('/CREATE TABLE (?!IF NOT EXISTS)/', $dualStatements) === 1) {
    throw new RuntimeException('Migration 043 must stay additive (six CREATE TABLE IF NOT EXISTS), grant nothing and write no row.');
}
// Finance verification requests (044, API 2.50.0): three new tables, no row, no grant; the only change to an existing table
// are the guarded swaps of the employee_finance_scopes capability CHECK (+ verify) and the order_finance_actions action
// CHECK (+ verify_transfer).
$requestSchema = (string) file_get_contents($root . '/database/migrations/044_finance_verification_requests.sql');
$requestStatements = preg_replace(['/^--.*$/m', '/ON (UPDATE|DELETE) RESTRICT/'], '', $requestSchema) ?? '';
foreach (['employee_finance_supervision', 'finance_verification_requests', 'finance_verification_request_events'] as $table) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS\s+' . $table . '\b/', $requestSchema) !== 1) {
        throw new RuntimeException("Migration 044 is missing table: {$table}");
    }
}
$requestOther = preg_replace('/ALTER TABLE (employee_finance_scopes (DROP CONSTRAINT chk_employee_finance_scopes_capability|ADD CONSTRAINT chk_employee_finance_scopes_capability_044 CHECK)|order_finance_actions (DROP CONSTRAINT chk_order_finance_actions_action|ADD CONSTRAINT chk_order_finance_actions_action_044 CHECK))/', '', $requestStatements) ?? '';
if (preg_match('/ON (DELETE|UPDATE) (CASCADE|SET NULL)|DROP TABLE|DELETE FROM|TRUNCATE|\bUPDATE |\bINSERT |ALTER TABLE|role_permissions|employee_role_assignments/i', $requestOther) === 1
    || preg_match_all('/CREATE TABLE IF NOT EXISTS/', $requestStatements) !== 3 || preg_match('/CREATE TABLE (?!IF NOT EXISTS)/', $requestStatements) === 1
    || !str_contains($requestSchema, 'UNIQUE KEY uq_finance_verification_requests_open (open_order_uuid)')
    || !str_contains($requestSchema, "CHECK (capability IN (''view'', ''record'', ''approve'', ''verify''))")
    || !str_contains($requestSchema, "''decide_refund'', ''verify_transfer''))")) {
    throw new RuntimeException('Migration 044 must stay additive (three CREATE TABLE IF NOT EXISTS and the capability CHECK swap), grant nothing and write no row.');
}
fwrite(STDOUT, 'Migration sanity checks passed for ' . count($files) . " file(s).\n");
