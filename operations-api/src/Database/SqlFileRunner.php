<?php

declare(strict_types=1);

namespace Arasya\Operations\Database;

use PDO;
use RuntimeException;

final readonly class SqlFileRunner
{
    public function __construct(private PDO $pdo)
    {
    }

    public function run(string $path): void
    {
        $sql = file_get_contents($path);
        if (!is_string($sql)) {
            throw new RuntimeException("Could not read SQL file: {$path}");
        }
        $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $this->pdo->exec($statement);
            }
        }
    }
}

