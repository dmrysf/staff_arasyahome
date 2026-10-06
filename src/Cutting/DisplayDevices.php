<?php
declare(strict_types=1);
namespace Arasya\Operations\Cutting;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Employee\EmployeeRepository;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Quality\IdempotencyStore;
use Arasya\Operations\Quality\LiveEvents;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use PDO;
use PDOException;
use Throwable;

/** Separate, scoped display sessions; no employee identity and no browser-storage credential. */
final readonly class DisplayDevices
{
    public function __construct(private PDO $pdo, private Config $config, private Clock $clock, private EmployeeRepository $employees, private IamAuditLogger $audit, private IdempotencyStore $idem) {}
    public function cookieName(): string { return $this->config->isProduction() ? '__Host-arasya_cutting_display' : 'arasya_cutting_display'; }
    public function cookie(string $raw, int $ttl = 2592000): string
    {
        return $this->cookieName() . '=' . rawurlencode($raw) . '; Path=/; Max-Age=' . $ttl . ($this->config->isProduction() ? '; Secure' : '') . '; HttpOnly; SameSite=Lax';
    }
    public function requireRoot(EmployeeIdentity $actor): void
    {
        if (!$actor->isRoot || !$actor->isOperationallyActive() || $actor->mustChangePassword || !$actor->hasApplication('dashboard')) throw new ApiException(403, 'ROOT_REQUIRED', 'Doar Root administrează dispozitivele și pragurile panoului.');
    }
    public function list(EmployeeIdentity $actor): array
    {
        $this->requireRoot($actor);
        $rows = $this->pdo->query('SELECT device_uuid, name, paired_at, last_seen_at, revoked_at, pairing_expires_at, session_expires_at, created_at FROM cutting_display_devices ORDER BY created_at DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
        return ['items' => array_map(fn(array $r): array => ['id' => $r['device_uuid'], 'name' => $r['name'], 'pairedAt' => $this->iso($r['paired_at']), 'lastSeenAt' => $this->iso($r['last_seen_at']), 'revokedAt' => $this->iso($r['revoked_at']), 'pairingExpiresAt' => $this->iso($r['pairing_expires_at']), 'sessionExpiresAt' => $this->iso($r['session_expires_at'])], $rows), 'settings' => $this->settings()];
    }
    public function settings(): array
    {
        $row = $this->pdo->query('SELECT first_minutes, second_minutes, third_minutes, version FROM cutting_board_settings WHERE singleton_id = 1')->fetch(PDO::FETCH_ASSOC);
        return ['thresholds' => [(int) $row['first_minutes'], (int) $row['second_minutes'], (int) $row['third_minutes']], 'version' => (int) $row['version']];
    }
    public function admin(EmployeeIdentity $actor, string $operation, ?string $id, array $input, string $key, string $requestId): array
    {
        $this->requireRoot($actor);
        IdempotencyStore::requireKey($key);
        $hash = IdempotencyStore::hash('display.' . $operation, $id ?? 'new', $input);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('SELECT employee_uuid FROM employees WHERE employee_uuid = ? FOR UPDATE')->execute([$actor->employeeUuid]);
            $actor = $this->employees->findByUuid($actor->employeeUuid) ?? $actor;
            $this->requireRoot($actor);
            $replay = $this->idem->replay($actor->employeeUuid, $key, 'display.' . $operation, $id ?? 'new', $hash);
            if ($replay !== null) { $this->pdo->commit(); return $replay; }
            $now = $this->now(); $code = null;
            if ($operation === 'thresholds') {
                $thresholds = $input['thresholds'] ?? null;
                $version = $input['expectedVersion'] ?? null;
                if (!is_array($thresholds) || array_keys($thresholds) !== [0,1,2] || count(array_filter($thresholds, 'is_int')) !== 3 || $thresholds[0] < 1 || $thresholds[0] >= $thresholds[1] || $thresholds[1] >= $thresholds[2] || $thresholds[2] > 1440 || !is_int($version)) throw new ApiException(422, 'INVALID_THRESHOLDS', 'Pragurile trebuie să crească (1–1440 min).');
                $this->pdo->query('SELECT singleton_id FROM cutting_board_settings WHERE singleton_id = 1 FOR UPDATE');
                $before = $this->settings();
                $s = $this->pdo->prepare('UPDATE cutting_board_settings SET first_minutes = ?, second_minutes = ?, third_minutes = ?, version = version + 1, updated_at = ? WHERE singleton_id = 1 AND version = ?');
                $s->execute([...$thresholds, $now, $version]);
                if ($s->rowCount() !== 1) throw new ApiException(409, 'SETTINGS_CHANGED', 'Setările s-au schimbat.');
                $result = $this->settings();
                $facts = ['before' => $before, 'after' => $result];
            } else {
                if ($operation === 'create') {
                    $name = $input['name'] ?? null;
                    if (!is_string($name) || mb_strlen(trim($name)) < 3 || mb_strlen($name) > 100) throw new ApiException(422, 'INVALID_DEVICE_NAME', 'Numele trebuie să aibă 3–100 caractere.');
                    $id = Uuid::v4();
                    $this->pdo->prepare('INSERT INTO cutting_display_devices (device_uuid, name, created_by_employee_uuid, created_at) VALUES (?, ?, ?, ?)')->execute([$id, trim($name), $actor->employeeUuid, $now]);
                } else {
                    $s = $this->pdo->prepare('SELECT device_uuid FROM cutting_display_devices WHERE device_uuid = ? FOR UPDATE'); $s->execute([$id]);
                    if ($s->fetchColumn() === false) throw new ApiException(404, 'DEVICE_NOT_FOUND', 'Dispozitivul nu a fost găsit.');
                }
                if ($operation === 'revoke') {
                    $this->pdo->prepare('UPDATE cutting_display_devices SET revoked_at = ?, pairing_hash = NULL, pairing_expires_at = NULL, session_hash = NULL, session_expires_at = NULL WHERE device_uuid = ?')->execute([$now, $id]);
                    $result = ['id' => $id, 'revoked' => true];
                } else {
                    $code = strtoupper(bin2hex(random_bytes(8)));
                    $expires = $this->clock->now()->modify('+10 minutes')->format('Y-m-d H:i:s.u');
                    $this->pdo->prepare('UPDATE cutting_display_devices SET pairing_hash = ?, pairing_expires_at = ?, session_hash = NULL, session_expires_at = NULL, revoked_at = NULL WHERE device_uuid = ?')->execute([$this->hash('pair', $code), $expires, $id]);
                    $result = ['id' => $id, 'pairingExpiresAt' => $this->iso($expires)];
                }
                $facts = $result;
            }
            $this->audit->record($actor, 'cutting.display.' . $operation, 'display_device', $id ?? 'thresholds', 'Zona de tăiere perdele', $facts, $requestId, $now);
            (new LiveEvents($this->pdo))->cuttingChanged($now);
            // Never persist the plaintext pairing code, including in idempotency responses.
            $this->idem->store($actor->employeeUuid, $key, 'display.' . $operation, $operation === 'create' ? 'new' : ($id ?? 'new'), $hash, $result, $now);
            $this->pdo->commit();
            return $code === null ? $result : $result + ['pairingCode' => $code];
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
    }
    /** Returns a cookie only in the HTTP response; never include it in JSON or logs. */
    public function pair(mixed $code, string $requestId): string
    {
        if (!is_string($code) || preg_match('/^[A-F0-9]{16}$/D', strtoupper(trim($code))) !== 1) throw new ApiException(422, 'PAIRING_INVALID', 'Cod invalid sau expirat.');
        $challenge = $this->hash('pair', strtoupper(trim($code)));
        // Resolve the immutable device link before the transaction. Locking through a disappearing
        // secondary pairing-hash key can deadlock two MariaDB consumers as the winner clears it.
        $hint = $this->pdo->prepare('SELECT device_uuid FROM cutting_display_devices WHERE pairing_hash = ?');
        $hint->execute([$challenge]); $id = $hint->fetchColumn();
        if (!is_string($id)) throw new ApiException(422, 'PAIRING_INVALID', 'Cod invalid sau expirat.');
        for ($attempt = 0; ; $attempt++) {
        $this->pdo->beginTransaction();
        try {
            $s = $this->pdo->prepare('SELECT pairing_hash, pairing_expires_at, revoked_at FROM cutting_display_devices WHERE device_uuid = ? FOR UPDATE');
            $s->execute([$id]); $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $row['pairing_hash'] === null || !hash_equals($challenge, $row['pairing_hash']) || $row['pairing_expires_at'] <= $this->now() || $row['revoked_at'] !== null) throw new ApiException(422, 'PAIRING_INVALID', 'Cod invalid sau expirat.');
            $raw = bin2hex(random_bytes(32));
            $expires = $this->clock->now()->modify('+30 days')->format('Y-m-d H:i:s.u');
            $this->pdo->prepare('UPDATE cutting_display_devices SET pairing_hash = NULL, pairing_expires_at = NULL, session_hash = ?, session_expires_at = ?, paired_at = ?, last_seen_at = ? WHERE device_uuid = ?')->execute([$this->hash('session', $raw), $expires, $this->now(), $this->now(), $id]);
            $this->audit->record(null, 'cutting.display.paired', 'display_device', $id, 'Dispozitiv de afișare', [], $requestId, $this->now());
            $this->pdo->commit();
            return $this->cookie($raw);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($error instanceof PDOException && $attempt < 2 && in_array((int) ($error->errorInfo[1] ?? 0), [1205,1213], true)) continue;
            throw $error;
        }
        }
    }
    public function authenticate(string $raw): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $raw) !== 1) throw new ApiException(401, 'DISPLAY_SESSION_INVALID', 'Asociază din nou dispozitivul cu Root.');
        $s = $this->pdo->prepare('SELECT device_uuid, name, session_expires_at FROM cutting_display_devices WHERE session_hash = ? AND revoked_at IS NULL AND session_expires_at > ?');
        $s->execute([$this->hash('session', $raw), $this->now()]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new ApiException(401, 'DISPLAY_SESSION_INVALID', 'Asociază din nou dispozitivul cu Root.');
        $this->pdo->prepare('UPDATE cutting_display_devices SET last_seen_at = ? WHERE device_uuid = ? AND (last_seen_at IS NULL OR last_seen_at < ?)')->execute([$this->now(), $row['device_uuid'], $this->clock->now()->modify('-1 minute')->format('Y-m-d H:i:s.u')]);
        return ['name' => $row['name'], 'expiresAt' => $this->iso($row['session_expires_at'])];
    }
    private function hash(string $purpose, string $raw): string { return hash_hmac('sha256', 'cutting-display/' . $purpose . '/' . $raw, $this->config->appSecret, true); }
    private function now(): string { return $this->clock->now()->format('Y-m-d H:i:s.u'); }
    private function iso(?string $value): ?string { return $value === null ? null : str_replace(' ', 'T', $value) . 'Z'; }
}
