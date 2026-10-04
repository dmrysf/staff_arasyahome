<?php

declare(strict_types=1);

namespace Arasya\Operations\Iam;

use Arasya\Operations\Database\DatabaseAdvisoryLock;
use Arasya\Operations\Security\PasswordHasher;
use Arasya\Operations\Security\UsernameNormalizer;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Creates the single protected root identity and recovers its password. Server CLI only: no HTTP
 * route reaches this class. Passwords are generated here and returned once to the caller.
 */
final readonly class RootBootstrapService
{
    public const ROOT_USERNAME = 'arasya.root.owner';

    public function __construct(private PDO $pdo, private PasswordHasher $passwords, private Clock $clock)
    {
    }

    public function bootstrap(string $requestId, string $username = self::ROOT_USERNAME): string
    {
        return $this->locked(function () use ($requestId, $username): string {
            if ((int) $this->pdo->query('SELECT COUNT(*) FROM system_root_identity')->fetchColumn() !== 0) {
                throw new RuntimeException('A root identity already exists; bootstrap refuses to create another.');
            }
            $normalized = (new UsernameNormalizer())->normalize($username);
            $exists = $this->pdo->prepare('SELECT COUNT(*) FROM employees WHERE username_normalized = :username');
            $exists->execute(['username' => $normalized]);
            if ((int) $exists->fetchColumn() !== 0) {
                throw new RuntimeException('The root username is already used by another identity.');
            }
            $now = $this->now();
            $this->pdo->prepare(
                "INSERT INTO departments (department_key, name, description, status, created_at, updated_at)
                 VALUES ('conducere', 'Conducere', 'Conducerea companiei', 'active', :created_at, :updated_at)
                 ON DUPLICATE KEY UPDATE department_key = department_key",
            )->execute(['created_at' => $now, 'updated_at' => $now]);
            $departmentId = (int) $this->pdo->query("SELECT department_id FROM departments WHERE department_key = 'conducere'")->fetchColumn();
            $roleId = (int) $this->pdo->query("SELECT role_id FROM roles WHERE role_key = 'employee'")->fetchColumn();
            if ($departmentId === 0 || $roleId === 0) {
                throw new RuntimeException('Reference data is missing; run the reference seed first.');
            }
            $password = TemporaryPasswordGenerator::generate(28);
            $uuid = Uuid::v4();
            $this->pdo->prepare(
                "INSERT INTO employees (employee_uuid, employee_code, username, username_normalized, password_hash, display_name, position_title, department_id, role_id, status, created_at, updated_at, password_changed_at, must_change_password, authorization_version)
                 VALUES (:uuid, NULL, :username, :normalized, :hash, 'Administrator principal', 'Administrator principal', :department_id, :role_id, 'active', :created_at, :updated_at, :changed_at, 1, 1)",
            )->execute(['uuid' => $uuid, 'username' => $username, 'normalized' => $normalized, 'hash' => $this->passwords->hash($password), 'department_id' => $departmentId, 'role_id' => $roleId, 'created_at' => $now, 'updated_at' => $now, 'changed_at' => $now]);
            $this->pdo->prepare(
                "INSERT INTO employee_application_access (employee_uuid, application_key, granted_at)
                 SELECT :uuid, application_key, :granted_at FROM applications WHERE status = 'active'",
            )->execute(['uuid' => $uuid, 'granted_at' => $now]);
            $this->pdo->prepare('INSERT INTO system_root_identity (singleton_id, employee_uuid, created_at) VALUES (1, :uuid, :created_at)')
                ->execute(['uuid' => $uuid, 'created_at' => $now]);
            (new IamAuditLogger($this->pdo))->record(null, 'root.bootstrapped', 'employee', $uuid, "Administrator principal ({$username})", [], $requestId, $now);
            return $password;
        });
    }

    /** Break-glass recovery: a new one-time password, forced change, every session revoked. */
    public function recoverPassword(string $requestId): string
    {
        return $this->locked(function () use ($requestId): string {
            $root = $this->pdo->query(
                'SELECT e.employee_uuid, e.username FROM system_root_identity sri INNER JOIN employees e ON e.employee_uuid = sri.employee_uuid FOR UPDATE',
            )->fetch();
            if (!is_array($root)) {
                throw new RuntimeException('No root identity exists; run the bootstrap instead.');
            }
            $now = $this->now();
            $password = TemporaryPasswordGenerator::generate(28);
            $this->pdo->prepare(
                "UPDATE employees SET password_hash = :hash, password_changed_at = :changed_at, must_change_password = 1, status = 'active',
                        authorization_version = authorization_version + 1, updated_at = :updated_at WHERE employee_uuid = :uuid",
            )->execute(['hash' => $this->passwords->hash($password), 'changed_at' => $now, 'updated_at' => $now, 'uuid' => $root['employee_uuid']]);
            $this->pdo->prepare('UPDATE auth_sessions SET revoked_at = :now WHERE employee_uuid = :uuid AND revoked_at IS NULL')
                ->execute(['now' => $now, 'uuid' => $root['employee_uuid']]);
            (new IamAuditLogger($this->pdo))->record(null, 'root.password_recovered', 'employee', (string) $root['employee_uuid'], "Administrator principal ({$root['username']})", [], $requestId, $now);
            return $password;
        });
    }

    /** @template T @param callable(): T $operation @return T */
    private function locked(callable $operation): mixed
    {
        $lock = new DatabaseAdvisoryLock($this->pdo, 'arasya_root_identity');
        $lock->acquire(5);
        try {
            $this->pdo->beginTransaction();
            try {
                $result = $operation();
                $this->pdo->commit();
                return $result;
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $error;
            }
        } finally {
            $lock->release();
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
