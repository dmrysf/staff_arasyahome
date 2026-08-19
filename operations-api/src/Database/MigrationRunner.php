<?php

declare(strict_types=1);

namespace Arasya\Operations\Database;

use PDO;
use RuntimeException;

final readonly class MigrationRunner
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<string> */
    public function migrate(string $directory): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration_name VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                applied_at DATETIME(6) NOT NULL,
                PRIMARY KEY (migration_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $files = glob(rtrim($directory, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $applied = [];
        $runner = new SqlFileRunner($this->pdo);
        foreach ($files as $file) {
            $name = basename($file);
            $checksum = hash_file('sha256', $file);
            if (!is_string($checksum)) {
                throw new RuntimeException("Could not hash migration: {$name}");
            }
            $query = $this->pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration_name = :name');
            $query->execute(['name' => $name]);
            $existing = $query->fetchColumn();
            if ($existing !== false) {
                if (!hash_equals((string) $existing, $checksum)) {
                    throw new RuntimeException("Applied migration checksum changed: {$name}");
                }
                continue;
            }
            $runner->run($file);
            $insert = $this->pdo->prepare('INSERT INTO schema_migrations (migration_name, checksum, applied_at) VALUES (:name, :checksum, UTC_TIMESTAMP(6))');
            $insert->execute(['name' => $name, 'checksum' => $checksum]);
            $applied[] = $name;
        }
        return $applied;
    }
}

