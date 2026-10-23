<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Integration\SourceAccess\SourceAccessClient;
use Arasya\Operations\Integration\SourceAccess\SourceAccessException;
use Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry;
use Arasya\Operations\Integration\SourceAccess\SourceOrderDetail;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Security\ApiRateLimiter;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use InvalidArgumentException;
use PDO;
use PDOException;

/**
 * The shop's own view of one Confirmare order for the operator (API 2.40.0, Phase 4A): the customer's real telephone
 * numbers and the commercial details, read from the shop at the moment the operator asks, through the signed source
 * access protocol (Staff never talks to WooCommerce; the browser never holds a shop credential).
 *
 * Who: the operator the pending order is assigned to (with a valid `operate` scope of the source), or a supervisor of
 * the source. Nobody else, and never an order of the legacy workflow (the shop refuses those too). The permission is
 * checked before the shop is asked and again after it answered, so a reassignment or a revoked scope during the read
 * returns nothing.
 *
 * What is kept: one order_confirmation_source_reads row per read (who, which order, outcome code). The answer itself is
 * relayed to the browser once and never stored, logged or cached here (Cache-Control: no-store). When the audit row
 * cannot be written, no customer data is returned.
 */
final readonly class ConfirmareSourceAccess
{
    public const READS_PER_MINUTE = 30;
    public const ROLE_ASSIGNEE = 'assignee';
    public const ROLE_SUPERVISOR = 'supervisor';

    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private ConfirmareScopePolicy $scopes,
        private SourceAccessRegistry $registry,
        private SourceAccessClient $client,
        private ApiRateLimiter $rateLimiter,
        private Clock $clock,
    ) {
    }

    /**
     * What the actor may do with the shop for this order (shown with the Confirmare detail).
     *
     * @return array{configured: bool, read: bool, edit: bool}
     */
    public function capabilities(EmployeeIdentity $actor, string $globalOrderId): array
    {
        $row = $this->row($this->globalId($globalOrderId));
        $target = $row === null ? null : $this->registry->find((string) $row['source_key']);
        $read = $row !== null && $target !== null && $row['workflow_cohort'] === 'confirmare' && $this->role($actor, $row) !== null;
        return ['configured' => $target !== null, 'read' => $read, 'edit' => $read && $target->allowsEdit() && $row['status'] === 'pending'];
    }

    /**
     * Who the actor is for this order's shop data (assignee or supervisor), or a refusal. Shared with the Phase 4B edits.
     *
     * @return array{row: array<string, mixed>, role: string, globalId: string}
     */
    public function context(EmployeeIdentity $actor, string $globalOrderId): array
    {
        $this->authorization->requireApplication($actor, $actor->hasApplication('staff') ? 'staff' : 'dashboard');
        $globalId = $this->globalId($globalOrderId);
        $row = $this->row($globalId);
        if ($row === null || !$this->scopes->sees($actor, (string) $row['source_key'])) {
            throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
        }
        $role = $this->role($actor, $row);
        if ($role === null) {
            throw new ApiException(403, 'CONFIRMARE_SOURCE_ACCESS_DENIED', 'Only the operator the order is assigned to or a supervisor of its source sees the shop order.');
        }
        if ($row['workflow_cohort'] !== 'confirmare') {
            // Older orders keep their existing workflow; their shop data is never opened through Confirmare.
            throw new ApiException(409, 'CONFIRMARE_SOURCE_NOT_ELIGIBLE', 'This order is not part of the Confirmare workflow.');
        }
        return ['row' => $row, 'role' => $role, 'globalId' => $globalId];
    }

    /** The actor's role for this order now (the re-check after a shop call), or null. */
    public function currentRole(EmployeeIdentity $actor, string $globalId): ?string
    {
        $row = $this->row($globalId);
        return $row === null ? null : $this->role($actor, $row);
    }

    /** @return array<string, mixed> */
    public function read(EmployeeIdentity $actor, string $globalOrderId, string $requestId): array
    {
        ['row' => $row, 'role' => $role, 'globalId' => $globalId] = $this->context($actor, $globalOrderId);
        if (!$this->rateLimiter->hit('confirmare-source-read', $actor->employeeUuid, self::READS_PER_MINUTE, 60)) {
            throw new ApiException(429, 'RATE_LIMITED', 'Too many shop reads. Try again in a minute.');
        }
        $target = $this->registry->find((string) $row['source_key']);
        if ($target === null) {
            $this->audit($row, $actor, $role, 'not_configured', $requestId);
            return ['available' => false, 'reason' => 'not_configured'];
        }
        try {
            $raw = $this->client->call($target, SourceAccessClient::ROUTE_DETAIL, [
                'orderId' => (string) $row['source_order_id'],
                'orderNumber' => (string) $row['order_number'],
                'actor' => ['employeeId' => $actor->employeeUuid, 'role' => $role],
                'requestId' => $this->requestId($requestId),
            ]);
            $detail = SourceOrderDetail::from($raw, (string) $row['source_order_id'], (string) $row['order_number']);
        } catch (SourceAccessException $e) {
            $this->audit($row, $actor, $role, strtolower($e->codeName), $requestId);
            return ['available' => false, 'reason' => strtolower($e->codeName)];
        }
        // The permission again, after the shop answered: a reassignment or a revoked scope during the read wins.
        $after = $this->row($globalId);
        if ($after === null || $this->role($actor, $after) !== $role) {
            $this->audit($row, $actor, $role, 'revoked_during_read', $requestId);
            throw new ApiException(403, 'CONFIRMARE_SOURCE_ACCESS_DENIED', 'Your access to this order changed while the shop was read.');
        }
        if (!$this->audit($row, $actor, $role, 'ok', $requestId, $detail['pricingFingerprint'])) {
            throw new ApiException(503, 'CONFIRMARE_AUDIT_UNAVAILABLE', 'The read could not be recorded, so nothing is shown. Try again.');
        }
        $stored = $after['source_pricing_fingerprint'] === null ? null : (string) $after['source_pricing_fingerprint'];
        $arasyaChanged = $after['source_changed_at'] === null ? null : self::iso((string) $after['source_changed_at']);
        $pricingMatches = $stored === null || $detail['pricingFingerprint'] === null ? null : hash_equals($stored, $detail['pricingFingerprint']);
        return [
            'available' => true,
            'role' => $role,
            'detail' => $detail,
            'freshness' => [
                'readAt' => $detail['readAt'],
                'shopModifiedAt' => $detail['order']['modifiedAt'],
                'arasyaChangedAt' => $arasyaChanged,
                // false: the shop's prices differ from those Arasya received (a change is still on its way, or an
                // approval was given on other prices); null: one side has no pricing fingerprint.
                'pricingMatches' => $pricingMatches,
                // Arasya holds the shop's latest saved state (prices and last modification) of this order.
                'inSync' => $pricingMatches !== false && ($detail['order']['modifiedAt'] === null || ($arasyaChanged !== null && $detail['order']['modifiedAt'] <= $arasyaChanged)),
            ],
        ];
    }

    /**
     * Whether the employee read the shop order through source access showing exactly the prices Arasya holds now, at or
     * after Arasya's last change of the order and after the last edit applied through Staff (API 2.40.0). The approval
     * needs it while source access is configured for the shop: nobody approves prices they did not see.
     */
    public static function reviewedShop(PDO $pdo, string $orderUuid, string $employeeUuid): bool
    {
        $statement = $pdo->prepare("SELECT 1 FROM order_confirmation_source_reads r INNER JOIN operational_orders o ON o.order_uuid = r.order_uuid
            WHERE r.order_uuid = ? AND r.employee_uuid = ? AND r.outcome = 'ok' AND o.source_pricing_fingerprint IS NOT NULL
              AND r.shop_pricing_fingerprint = o.source_pricing_fingerprint AND (o.source_changed_at IS NULL OR r.read_at >= o.source_changed_at)
              AND NOT EXISTS (SELECT 1 FROM order_confirmation_source_edits e WHERE e.order_uuid = r.order_uuid AND e.state = 'applied' AND e.completed_at > r.read_at) LIMIT 1");
        $statement->execute([$orderUuid, $employeeUuid]);
        return $statement->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $row */
    private function role(EmployeeIdentity $actor, array $row): ?string
    {
        $source = (string) $row['source_key'];
        if ($this->scopes->allows($actor, ConfirmareScopePolicy::SUPERVISE, $source)) {
            return self::ROLE_SUPERVISOR;
        }
        if ($row['status'] === 'pending' && $row['assigned_employee_uuid'] === $actor->employeeUuid && $this->scopes->allows($actor, ConfirmareScopePolicy::OPERATE, $source)) {
            return self::ROLE_ASSIGNEE;
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    private function row(string $globalId): ?array
    {
        $statement = $this->pdo->prepare('SELECT o.order_uuid, o.global_order_id, o.source_key, o.source_order_id, o.order_number, o.workflow_cohort, o.source_pricing_fingerprint,
                o.source_changed_at, c.status, c.version, c.assigned_employee_uuid
            FROM order_confirmations c INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid WHERE o.global_order_id = ?');
        $statement->execute([$globalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $row */
    private function audit(array $row, EmployeeIdentity $actor, string $role, string $outcome, string $requestId, ?string $shopPricing = null): bool
    {
        try {
            $this->pdo->prepare('INSERT INTO order_confirmation_source_reads (read_uuid, order_uuid, global_order_id, source_key, employee_uuid, access_role, outcome, shop_pricing_fingerprint, request_id, read_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                    Uuid::v4(), (string) $row['order_uuid'], (string) $row['global_order_id'], (string) $row['source_key'], $actor->employeeUuid, $role,
                    substr(preg_replace('/[^a-z_]/', '', $outcome) ?? 'unknown', 0, 40), $outcome === 'ok' ? $shopPricing : null, $this->requestId($requestId), $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                ]);
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    private function requestId(string $requestId): string
    {
        return preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $requestId) === 1 ? $requestId : 'none';
    }

    private function globalId(string $globalOrderId): string
    {
        try {
            return GlobalOrderId::fromString($globalOrderId)->toString();
        } catch (InvalidArgumentException) {
            throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
        }
    }

    private static function iso(string $sql): string
    {
        return (new \DateTimeImmutable($sql, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
