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
fwrite(STDOUT, 'Migration sanity checks passed for ' . count($files) . " file(s).\n");
