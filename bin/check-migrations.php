<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = glob($root . '/database/migrations/*.sql') ?: [];
sort($files, SORT_STRING);
if ($files === []) {
    throw new RuntimeException('No database migrations were found.');
}
$expected = ['departments', 'roles', 'permissions', 'role_permissions', 'employees', 'employee_stage_access', 'auth_sessions', 'auth_login_attempts', 'auth_rate_limit_buckets', 'auth_audit_events', 'production_workflows', 'production_stages'];
$schema = implode("\n", array_map(static fn (string $path): string => (string) file_get_contents($path), $files));
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
fwrite(STDOUT, 'Migration sanity checks passed for ' . count($files) . " file(s).\n");
