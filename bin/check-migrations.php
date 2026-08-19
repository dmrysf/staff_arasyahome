<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = glob($root . '/database/migrations/*.sql') ?: [];
sort($files, SORT_STRING);
if ($files === []) {
    throw new RuntimeException('No database migrations were found.');
}
$expected = ['departments', 'roles', 'permissions', 'role_permissions', 'employees', 'employee_stage_access', 'auth_sessions', 'auth_login_attempts', 'auth_rate_limit_buckets', 'auth_audit_events'];
$schema = implode("\n", array_map(static fn (string $path): string => (string) file_get_contents($path), $files));
foreach ($expected as $table) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS\s+' . preg_quote($table, '/') . '\b/i', $schema) !== 1) {
        throw new RuntimeException("Migration is missing table: {$table}");
    }
}
if (!str_contains($schema, 'token_hash BINARY(32)')) {
    throw new RuntimeException('Session token hashes must use fixed 32-byte storage.');
}
fwrite(STDOUT, 'Migration sanity checks passed for ' . count($files) . " file(s).\n");
