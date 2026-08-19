<?php

declare(strict_types=1);

namespace Arasya\Operations\Database;

use PDO;
use PDOException;
use RuntimeException;

final readonly class MigrationStatus
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array{name: string, checksum: string, status: 'APPLIED'|'PENDING'}> */
    public function inspect(string $directory): array
    {
        $applied = $this->appliedChecksums();
        $files = glob(rtrim($directory, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $status = [];
        foreach ($files as $file) {
            $name = basename($file);
            $checksum = hash_file('sha256', $file);
            if (!is_string($checksum)) {
                throw new RuntimeException("Could not hash migration: {$name}");
            }
            if (isset($applied[$name]) && !hash_equals($applied[$name], $checksum)) {
                throw new RuntimeException("Applied migration checksum changed: {$name}");
            }
            $status[] = [
                'name' => $name,
                'checksum' => $checksum,
                'status' => isset($applied[$name]) ? 'APPLIED' : 'PENDING',
            ];
        }
        return $status;
    }

    /** @return array<string, string> */
    private function appliedChecksums(): array
    {
        try {
            $rows = $this->pdo->query('SELECT migration_name, checksum FROM schema_migrations')->fetchAll();
        } catch (PDOException $error) {
            $message = strtolower($error->getMessage());
            if (str_contains($message, 'doesn\'t exist') || str_contains($message, 'no such table')) {
                return [];
            }
            throw $error;
        }
        $applied = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $applied[(string) $row['migration_name']] = (string) $row['checksum'];
            }
        }
        return $applied;
    }
}
