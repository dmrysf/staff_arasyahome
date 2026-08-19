<?php

declare(strict_types=1);

namespace Arasya\Operations\Database;

use PDO;
use RuntimeException;

final class DatabaseAdvisoryLock
{
    private bool $held = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $name,
    ) {
        if (preg_match('/^[a-z0-9_]{1,64}$/', $this->name) !== 1) {
            throw new RuntimeException('Database advisory lock name is invalid.');
        }
    }

    public function acquire(int $waitSeconds): void
    {
        if ($waitSeconds < 0 || $waitSeconds > 30) {
            throw new RuntimeException('Database advisory lock wait is invalid.');
        }
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, :wait_seconds)');
        $statement->bindValue(':lock_name', $this->name);
        $statement->bindValue(':wait_seconds', $waitSeconds, PDO::PARAM_INT);
        $statement->execute();
        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('The database maintenance lock is already held.');
        }
        $this->held = true;
    }

    public function release(): void
    {
        if (!$this->held || $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }
        try {
            $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $statement->execute(['lock_name' => $this->name]);
        } finally {
            $this->held = false;
        }
    }
}
