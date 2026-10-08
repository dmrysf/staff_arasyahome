<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Audit\AuditLogger;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\B2B\Pdf\QrCode;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Order\OrderOperationsService;
use Arasya\Operations\Order\QrReference;
use Arasya\Operations\Support\Clock;
use InvalidArgumentException;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Production QR authority: Arasya is the only issuer of production QR identities (order_qr_references).
 *
 * - inspect: the manager view of one order's QR authority, active revision (payload and SVG preview) and
 *   revision history;
 * - rotate: a manager replaces the active QR of an Arasya-managed order that has no central production
 *   document (lost, damaged or compromised label). One transaction locks the order row, checks the
 *   expected QR revision, retires the active reference as `superseded` and issues the next one, then
 *   writes the QR evidence, the IAM audit and the idempotency result. The database allows one active
 *   reference per order, so two concurrent rotations can never both leave an active code. An order with
 *   a central document rotates only through its document revision (approval) workflow;
 * - sourceStates: the signed read-only answer that lets a source (YD SOFT) mirror QR authority and print
 *   the Arasya QR of operations orders. It never issues or changes anything;
 * - reconcile: the bounded operator reconciliation of existing operations orders (keep an active QR,
 *   issue one only where none exists, stop on any anomaly).
 *
 * Ingestion, commerce updates, cancellations, stage transitions, takeovers and releases never rotate a QR.
 */
final readonly class ProductionQrService
{
    public const PERMISSION = ProductionAuthorityService::PERMISSION;
    public const OPERATION_ROTATE = 'qr_rotate';
    public const AUDIT_ROTATED = 'production.qr.rotated';
    public const AUDIT_DENIED = 'PRODUCTION_QR_DENIED';
    public const ROTATION_REASONS = ['label_lost', 'label_damaged', 'security'];
    public const MAX_SOURCE_ORDERS = 50;
    public const MAX_RECONCILE_ORDERS = 200;

    private const MAX_ATTEMPTS = 3;
    private const RETRYABLE_MYSQL_ERRORS = [1062, 1205, 1213];
    private const ORDER_COLUMNS = 'order_uuid, global_order_id, source_key, source_order_id, order_number, production_authority, production_completed_at, operational_status, document_status';

    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private ProductionAuthorityModes $modes,
        private IamAuditLogger $iamAudit,
        private AuditLogger $securityAudit,
        private Clock $clock,
    ) {
    }

    /**
     * Whether the Arasya QR is the production identity of an order: always for internal (B2B) orders,
     * and for a signed source's operations orders once its QR authority mode is observe or enforce.
     */
    public static function qrAuthorityOf(ProductionAuthorityModes $modes, string $sourceKey, string $productionAuthority): string
    {
        if (!$modes->isManagedSource($sourceKey)) {
            return $sourceKey === 'b2b' ? 'arasya' : 'source';
        }
        return $modes->qrModeFor($sourceKey)->isActive() && $productionAuthority === ProductionAuthority::OPERATIONS ? 'arasya' : 'source';
    }

    /** @return array<string, mixed> */
    public function inspect(EmployeeIdentity $actor, string $globalOrderId): array
    {
        $this->authorization->require($actor, self::PERMISSION);
        return $this->view($this->order($this->globalId($globalOrderId), false));
    }

    /**
     * @param array<string, mixed> $input {expectedQrRevision: int, reason: label_lost|label_damaged|security}
     * @return array<string, mixed> the order's QR view after the rotation
     */
    public function rotate(EmployeeIdentity $actor, string $globalOrderId, array $input, string $idempotencyKey, string $requestId, string $ipAddress = '', string $userAgent = ''): array
    {
        try {
            $this->authorization->require($actor, self::PERMISSION);
            if (array_diff(array_keys($input), ['expectedQrRevision', 'reason']) !== [] || !is_int($input['expectedQrRevision'] ?? null) || $input['expectedQrRevision'] < 1
                || !in_array($input['reason'] ?? null, self::ROTATION_REASONS, true)) {
                throw new ApiException(400, 'INVALID_REQUEST', 'expectedQrRevision and a valid reason are required.');
            }
            if (!OrderOperationsService::isValidIdempotencyKey($idempotencyKey)) {
                throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key header is required.');
            }
            $globalId = $this->globalId($globalOrderId);
            $hash = hash('sha256', implode('|', [self::OPERATION_ROTATE, $globalId, (string) $input['expectedQrRevision'], $input['reason']]), true);
            return $this->retrying(fn (): array => $this->attemptRotate($actor, $globalId, $input['expectedQrRevision'], $input['reason'], $idempotencyKey, $hash, $requestId));
        } catch (ApiException $error) {
            $this->recordDenied($actor, $globalOrderId, $error, $requestId, $ipAddress, $userAgent);
            throw $error;
        }
    }

    /**
     * Signed source answer for up to 50 of the source's own orders, in request order. Read-only.
     *
     * @param list<string> $orderIds
     * @return list<array<string, mixed>>
     */
    public function sourceStates(string $sourceKey, array $orderIds): array
    {
        $states = [];
        $statement = $this->pdo->prepare('SELECT ' . self::ORDER_COLUMNS . ' FROM operational_orders WHERE global_order_id = ?');
        foreach ($orderIds as $orderId) {
            $globalId = $sourceKey . ':' . $orderId;
            $statement->execute([$globalId]);
            $order = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($order)) {
                $states[] = ['orderId' => $orderId, 'globalOrderId' => $globalId, 'exists' => false, 'productionAuthority' => null, 'qrAuthority' => null, 'qrIssueRequired' => false, 'qr' => null];
                continue;
            }
            $authority = self::qrAuthorityOf($this->modes, $sourceKey, (string) $order['production_authority']);
            $active = $authority === 'arasya' ? $this->activeQr((string) $order['order_uuid']) : null;
            $states[] = [
                'orderId' => $orderId,
                'globalOrderId' => $globalId,
                'exists' => true,
                'productionAuthority' => (string) $order['production_authority'],
                'qrAuthority' => $authority,
                'qrIssueRequired' => $authority === 'arasya' && $active === null,
                'qr' => $active === null ? null : [
                    'payload' => QrReference::fromStored($active['reference'])->payload(),
                    'revision' => $active['revision'],
                    'hint' => ProductionQrLedger::hint($active['reference']),
                    'issuedAt' => self::iso($active['issuedAt']),
                    'documentRevision' => $active['documentRevision'],
                ],
            ];
        }
        return $states;
    }

    /**
     * Bounded reconciliation of a source's operations orders, oldest first. Dry run unless $apply.
     * Keeps every existing active QR; issues one only where none exists; never touches source-managed
     * orders; reports (and never repairs) any order with more than one active reference.
     *
     * @param list<string>|null $orderIds restrict to these source order ids
     * @return array{source: string, mode: string, apply: bool, results: list<array{order: string, result: string, revision: int|null, hint: string|null}>}
     */
    public function reconcile(string $sourceKey, ?array $orderIds, int $limit, bool $apply, string $requestId): array
    {
        $limit = max(1, min(self::MAX_RECONCILE_ORDERS, $limit));
        $mode = $this->modes->qrModeFor($sourceKey);
        if (!$this->modes->isManagedSource($sourceKey) || !$mode->isActive()) {
            throw new ApiException(409, 'QR_CUTOVER_DISABLED', 'QR authority is not enabled for this source.');
        }
        if ($orderIds !== null) {
            $ids = array_map(static fn (string $id): string => $sourceKey . ':' . $id, $orderIds);
            $statement = $this->pdo->prepare('SELECT global_order_id FROM operational_orders WHERE source_key = ? AND global_order_id IN (' . implode(',', array_fill(0, max(1, count($ids)), '?')) . ") AND production_authority = 'operations' ORDER BY created_at, order_uuid LIMIT {$limit}");
            $statement->execute([$sourceKey, ...($ids === [] ? [''] : $ids)]);
        } else {
            $statement = $this->pdo->prepare("SELECT global_order_id FROM operational_orders WHERE source_key = ? AND production_authority = 'operations' ORDER BY created_at, order_uuid LIMIT {$limit}");
            $statement->execute([$sourceKey]);
        }
        $results = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $globalId) {
            $results[] = $this->reconcileOne((string) $globalId, $mode, $apply, $requestId);
        }
        return ['source' => $sourceKey, 'mode' => $mode->value, 'apply' => $apply, 'results' => $results];
    }

    /** @return array{order: string, result: string, revision: int|null, hint: string|null} */
    private function reconcileOne(string $globalId, QrAuthorityMode $mode, bool $apply, string $requestId): array
    {
        $this->pdo->beginTransaction();
        try {
            $order = $this->order($globalId, true);
            $statement = $this->pdo->prepare("SELECT qr_reference FROM order_qr_references WHERE order_uuid = ? AND status = 'active'");
            $statement->execute([$order['order_uuid']]);
            $active = $statement->fetchAll(PDO::FETCH_COLUMN);
            if ($order['production_authority'] !== ProductionAuthority::OPERATIONS) {
                $this->pdo->rollBack();
                return ['order' => $globalId, 'result' => 'skipped_source_authority', 'revision' => null, 'hint' => null];
            }
            if (count($active) > 1) {
                $this->pdo->rollBack();
                return ['order' => $globalId, 'result' => 'anomaly_multiple_active', 'revision' => null, 'hint' => null];
            }
            $now = $this->now();
            if (count($active) === 1) {
                $reference = (string) $active[0];
                if ($apply) {
                    ProductionQrLedger::record($this->pdo, $order, ProductionQrLedger::ACTION_RECONCILED, $reference, null, null, 'active_reused', $mode->value, $requestId, $now);
                    $this->pdo->commit();
                } else {
                    $this->pdo->rollBack();
                }
                return ['order' => $globalId, 'result' => 'reused', 'revision' => ProductionQrLedger::revisionOf($this->pdo, (string) $order['order_uuid'], $reference), 'hint' => ProductionQrLedger::hint($reference)];
            }
            if (!$apply) {
                $this->pdo->rollBack();
                return ['order' => $globalId, 'result' => 'would_issue', 'revision' => null, 'hint' => null];
            }
            $reference = ProductionQrLedger::ensureActive($this->pdo, $order, $mode->value, 'reconciliation_missing_active', null, $requestId, $now)
                ?? throw new RuntimeException('QR reconciliation lost its lock.');
            ProductionQrLedger::record($this->pdo, $order, ProductionQrLedger::ACTION_RECONCILED, $reference, null, null, 'active_issued', $mode->value, $requestId, $now);
            $this->pdo->commit();
            return ['order' => $globalId, 'result' => 'issued', 'revision' => ProductionQrLedger::revisionOf($this->pdo, (string) $order['order_uuid'], $reference), 'hint' => ProductionQrLedger::hint($reference)];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string, mixed> */
    private function attemptRotate(EmployeeIdentity $actor, string $globalId, int $expectedRevision, string $reason, string $idempotencyKey, string $hash, string $requestId): array
    {
        $this->pdo->beginTransaction();
        try {
            $order = $this->order($globalId, true);
            $orderUuid = (string) $order['order_uuid'];
            $replay = $this->replay($actor->employeeUuid, $idempotencyKey, $orderUuid, $hash);
            if ($replay !== null) {
                $this->pdo->rollBack();
                return $replay;
            }
            $blocked = $this->rotateBlockedReason($order);
            if ($blocked !== null) {
                throw $this->blockedError($blocked);
            }
            $active = $this->activeQr($orderUuid);
            if ($active === null || $active['revision'] !== $expectedRevision) {
                throw new ApiException(409, 'QR_CHANGED', 'Codul QR al comenzii s-a schimbat. Reîncarcă pagina.');
            }
            $now = $this->now();
            $mode = $this->modes->qrModeFor((string) $order['source_key'])->value;
            ProductionQrLedger::retireActive($this->pdo, $orderUuid, ProductionQrLedger::RETIRED_SUPERSEDED, $now);
            $reference = QrReference::generate()->value;
            $this->pdo->prepare("INSERT INTO order_qr_references (qr_reference, order_uuid, status, created_at) VALUES (?, ?, 'active', ?)")->execute([$reference, $orderUuid, $now]);
            ProductionQrLedger::record($this->pdo, $order, ProductionQrLedger::ACTION_ROTATED, $reference, $active['reference'], $actor->employeeUuid, $reason, $mode, $requestId, $now);
            $this->iamAudit->record($actor, self::AUDIT_ROTATED, 'order', $globalId, "{$order['order_number']} · {$order['source_key']}", [
                'source' => (string) $order['source_key'],
                'qrAuthorityMode' => $mode,
                'reason' => $reason,
                'qrRevision' => ['before' => $expectedRevision, 'after' => $expectedRevision + 1],
                'qrHint' => ['before' => ProductionQrLedger::hint($active['reference']), 'after' => ProductionQrLedger::hint($reference)],
            ], $requestId, $now);
            $response = $this->view($this->order($globalId, false));
            $this->storeResult($actor->employeeUuid, $idempotencyKey, $orderUuid, $hash, $response, $now);
            $this->pdo->commit();
            return $response;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** Why a rotation is impossible right now, independent of the actor. @param array<string, mixed> $order */
    private function rotateBlockedReason(array $order): ?string
    {
        $source = (string) $order['source_key'];
        return match (true) {
            !$this->modes->isManagedSource($source) => 'qr_source_not_supported',
            !$this->modes->qrModeFor($source)->isActive() => 'qr_cutover_disabled',
            $order['production_authority'] !== ProductionAuthority::OPERATIONS => 'qr_not_arasya',
            $order['production_completed_at'] !== null => 'production_completed',
            $order['operational_status'] === 'unavailable' => 'order_unavailable',
            $order['document_status'] !== 'none' => 'qr_document_controlled',
            default => null,
        };
    }

    private function blockedError(string $reason): ApiException
    {
        return match ($reason) {
            'qr_source_not_supported' => new ApiException(409, 'QR_NOT_SUPPORTED', 'Codul QR al acestei comenzi se schimbă doar prin revizia documentului de producție.'),
            'qr_cutover_disabled' => new ApiException(409, 'QR_CUTOVER_DISABLED', 'Autoritatea QR Arasya nu este activă pentru această sursă.'),
            'qr_not_arasya' => new ApiException(409, 'QR_NOT_ARASYA', 'Producția acestei comenzi nu este gestionată în Arasya.'),
            'production_completed' => new ApiException(409, 'PRODUCTION_COMPLETED', 'Producția comenzii este finalizată.'),
            'order_unavailable' => new ApiException(409, 'ORDER_UNAVAILABLE', 'Comanda nu este disponibilă pentru producție.'),
            default => new ApiException(409, 'QR_DOCUMENT_CONTROLLED', 'Comanda are un document de producție: codul QR se schimbă doar printr-o revizie aprobată a documentului.'),
        };
    }

    /** @param array<string, mixed> $order @return array<string, mixed> */
    private function view(array $order): array
    {
        $source = (string) $order['source_key'];
        $active = $this->activeQr((string) $order['order_uuid']);
        $blocked = $this->rotateBlockedReason($order);
        return [
            'globalOrderId' => (string) $order['global_order_id'],
            'orderNumber' => (string) $order['order_number'],
            'source' => $source,
            'qrAuthorityMode' => $this->modes->isManagedSource($source) ? $this->modes->qrModeFor($source)->value : ProductionQrLedger::MODE_INTERNAL,
            'productionAuthority' => (string) $order['production_authority'],
            'qrAuthority' => self::qrAuthorityOf($this->modes, $source, (string) $order['production_authority']),
            'active' => $active === null ? null : [
                'revision' => $active['revision'],
                'issuedAt' => self::iso($active['issuedAt']),
                'hint' => ProductionQrLedger::hint($active['reference']),
                'documentRevision' => $active['documentRevision'],
                'payload' => QrReference::fromStored($active['reference'])->payload(),
                'svg' => self::svg(QrReference::fromStored($active['reference'])->payload()),
            ],
            'history' => array_map(static fn (array $row): array => [
                'revision' => $row['revision'],
                'state' => $row['state'],
                'issuedAt' => self::iso($row['issuedAt']),
                'retiredAt' => $row['retiredAt'] === null ? null : self::iso($row['retiredAt']),
                'hint' => ProductionQrLedger::hint($row['reference']),
                'documentRevision' => $row['documentRevision'],
            ], array_reverse(ProductionQrLedger::history($this->pdo, (string) $order['order_uuid']))),
            'rotate' => ['allowed' => $blocked === null && $active !== null, 'blockedReason' => $blocked ?? ($active === null ? 'qr_missing' : null), 'reasons' => self::ROTATION_REASONS],
        ];
    }

    /** @return array{reference: string, revision: int, issuedAt: string, documentRevision: int|null}|null */
    private function activeQr(string $orderUuid): ?array
    {
        $statement = $this->pdo->prepare('SELECT q.qr_reference, q.created_at, r.revision_number FROM order_qr_references q LEFT JOIN production_document_revisions r ON r.qr_reference = q.qr_reference WHERE q.active_order_uuid = ?');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return [
            'reference' => (string) $row['qr_reference'],
            'revision' => ProductionQrLedger::revisionOf($this->pdo, $orderUuid, (string) $row['qr_reference']),
            'issuedAt' => (string) $row['created_at'],
            'documentRevision' => $row['revision_number'] === null ? null : (int) $row['revision_number'],
        ];
    }

    /** Scalable black-on-white SVG of a payload (quiet zone of four modules), rendered on the server. */
    public static function svg(string $payload): string
    {
        $matrix = QrCode::matrix($payload, 'Q');
        $size = count($matrix);
        $path = '';
        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= 'M' . ($x + 4) . ' ' . ($y + 4) . 'h1v1h-1z';
                }
            }
        }
        $box = $size + 8;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $box . ' ' . $box . '" shape-rendering="crispEdges" role="img" aria-label="Cod QR de producție"><rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' . $path . '"/></svg>';
    }

    private static function iso(string $sql): string
    {
        return (new \DateTimeImmutable($sql, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    /** @return array<string, mixed> */
    private function order(string $globalId, bool $lock): array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::ORDER_COLUMNS . ' FROM operational_orders WHERE global_order_id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$globalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order was not found.');
    }

    private function globalId(string $globalOrderId): string
    {
        try {
            return GlobalOrderId::fromString($globalOrderId)->toString();
        } catch (InvalidArgumentException) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order was not found.');
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }

    /** @return array<string, mixed>|null */
    private function replay(string $actorUuid, string $idempotencyKey, string $orderUuid, string $hash): ?array
    {
        $statement = $this->pdo->prepare('SELECT operation, order_uuid, request_hash, response_json FROM order_operation_idempotency WHERE employee_uuid = ? AND idempotency_key = ? FOR UPDATE');
        $statement->execute([$actorUuid, $idempotencyKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if ($row['operation'] !== self::OPERATION_ROTATE || $row['order_uuid'] !== $orderUuid || !hash_equals((string) $row['request_hash'], $hash)) {
            throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different request.');
        }
        try {
            $decoded = json_decode((string) $row['response_json'], true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Stored idempotent result is unreadable.');
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Stored idempotent result is unreadable.');
        }
        // The stored answer omits the payload and preview; a replay re-reads the current view.
        $globalId = (string) ($decoded['globalOrderId'] ?? '');
        return $this->view($this->order($globalId, false));
    }

    /** @param array<string, mixed> $response */
    private function storeResult(string $actorUuid, string $idempotencyKey, string $orderUuid, string $hash, array $response, string $now): void
    {
        // Never persist a QR payload in the idempotency table: only the identity of the result.
        $stored = ['globalOrderId' => $response['globalOrderId'], 'activeRevision' => $response['active']['revision'] ?? null];
        $statement = $this->pdo->prepare('INSERT INTO order_operation_idempotency (employee_uuid, idempotency_key, operation, order_uuid, request_hash, response_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->bindValue(1, $actorUuid);
        $statement->bindValue(2, $idempotencyKey);
        $statement->bindValue(3, self::OPERATION_ROTATE);
        $statement->bindValue(4, $orderUuid);
        $statement->bindValue(5, $hash, PDO::PARAM_LOB);
        $statement->bindValue(6, json_encode($stored, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $statement->bindValue(7, $now);
        $statement->execute();
    }

    private function recordDenied(EmployeeIdentity $actor, string $globalOrderId, ApiException $error, string $requestId, string $ipAddress, string $userAgent): void
    {
        try {
            $this->securityAudit->record(self::AUDIT_DENIED, $actor->employeeUuid, null, $ipAddress, $userAgent, $requestId, $this->now(), [
                'order' => mb_substr($globalOrderId, 0, 191),
                'operation' => 'rotate',
                'code' => $error->errorCode,
            ]);
        } catch (Throwable) {
            // A failed denial audit must never turn a safe rejection into a different outcome.
        }
    }

    /** @param callable(): array<string, mixed> $attempt @return array<string, mixed> */
    private function retrying(callable $attempt): array
    {
        for ($try = 1; ; $try++) {
            try {
                return $attempt();
            } catch (PDOException $error) {
                if ($try < self::MAX_ATTEMPTS && in_array((int) ($error->errorInfo[1] ?? 0), self::RETRYABLE_MYSQL_ERRORS, true)) {
                    continue;
                }
                throw $error;
            }
        }
    }
}
