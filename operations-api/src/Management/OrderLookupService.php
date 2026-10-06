<?php

declare(strict_types=1);

namespace Arasya\Operations\Management;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Order\OrderLookupCode;
use Arasya\Operations\Quality\ApproverPolicy;
use Arasya\Operations\Quality\ExceptionQueries;
use Arasya\Operations\Security\ApiRateLimiter;
use InvalidArgumentException;
use PDO;

/**
 * Exact order search for narrow Dashboard roles (operations managers). There is deliberately no list:
 * the caller must type a complete order number (or a source:id global id). Only exact matches are
 * returned; several rows appear only when the same number exists in different sources. Prefixes,
 * wildcards, paging and empty queries return nothing. Lookups are rate limited per identity.
 *
 * The detail contains operational data only (source, number, stage, lines and meters, current owner,
 * production exception history). Operational orders carry no customer personal data.
 */
final readonly class OrderLookupService
{
    private const MAX_MATCHES = 10;
    private const LIMIT = 30;
    private const WINDOW_SECONDS = 60;

    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private ApproverPolicy $approvers,
        private ExceptionQueries $exceptions,
        private ApiRateLimiter $rateLimiter,
    ) {
    }

    /** @return array<string, mixed> */
    public function search(EmployeeIdentity $actor, string $number): array
    {
        $this->requireAccess($actor);
        $this->limit($actor);
        $number = trim($number);
        if (str_contains($number, ':')) {
            try {
                $where = 'o.global_order_id = ?';
                [$source, $sourceOrderId] = explode(':', $number, 2);
                $value = GlobalOrderId::fromString(strtolower(trim($source)) . ':' . trim($sourceOrderId))->toString();
            } catch (InvalidArgumentException) {
                throw new ApiException(400, 'INVALID_LOOKUP_CODE', 'The order number is not valid.');
            }
        } else {
            $value = OrderLookupCode::normalizeInput($number) ?? throw new ApiException(400, 'INVALID_LOOKUP_CODE', 'The order number is not valid.');
            $where = 'o.order_lookup_code = ?';
        }
        $statement = $this->pdo->prepare(
            "SELECT o.global_order_id, o.order_number, o.source_key, s.display_name AS source_name, o.production_stage_id, ps.display_name AS stage_label,
                    o.production_completed_at, o.operational_status, o.open_exception_uuid
             FROM operational_orders o
             INNER JOIN order_sources s ON s.source_key = o.source_key
             INNER JOIN production_stages ps ON ps.stage_id = o.production_stage_id
             WHERE {$where} ORDER BY o.source_key, o.global_order_id LIMIT " . self::MAX_MATCHES,
        );
        $statement->execute([$value]);
        return ['items' => array_map(static fn (array $row): array => [
            'id' => (string) $row['global_order_id'],
            'orderNumber' => (string) $row['order_number'],
            'source' => (string) $row['source_key'],
            'sourceName' => (string) $row['source_name'],
            'stage' => ['id' => (string) $row['production_stage_id'], 'label' => (string) $row['stage_label']],
            'completed' => $row['production_completed_at'] !== null,
            'unavailable' => $row['operational_status'] === 'unavailable',
            'blockedByException' => $row['open_exception_uuid'] !== null,
        ], $statement->fetchAll(PDO::FETCH_ASSOC))];
    }

    /** @return array<string, mixed> */
    public function detail(EmployeeIdentity $actor, string $globalOrderId): array
    {
        $this->requireAccess($actor);
        try {
            $globalId = GlobalOrderId::fromString($globalOrderId)->toString();
        } catch (InvalidArgumentException) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order was not found.');
        }
        $statement = $this->pdo->prepare(
            "SELECT o.order_uuid, o.global_order_id, o.order_number, o.source_key, s.display_name AS source_name, o.production_stage_id, ps.display_name AS stage_label,
                    ps.ordinal, o.production_completed_at, o.operational_status, o.production_version, o.open_exception_uuid, o.production_claimed_at,
                    e.display_name AS owner_name, d.name AS owner_department
             FROM operational_orders o
             INNER JOIN order_sources s ON s.source_key = o.source_key
             INNER JOIN production_stages ps ON ps.stage_id = o.production_stage_id
             LEFT JOIN employees e ON e.employee_uuid = o.production_owner_employee_uuid
             LEFT JOIN departments d ON d.department_id = e.department_id
             WHERE o.global_order_id = ?",
        );
        $statement->execute([$globalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order was not found.');
        $items = $this->pdo->prepare('SELECT item_uuid, line_number, name, product_code, variant, color, meters, quantity FROM operational_order_items WHERE order_uuid = ? ORDER BY line_number');
        $items->execute([$row['order_uuid']]);
        $quality = $this->exceptions->orderQuality((string) $row['order_uuid'], null, $actor->employeeUuid);
        return [
            'order' => [
                'id' => (string) $row['global_order_id'],
                'orderNumber' => (string) $row['order_number'],
                'source' => (string) $row['source_key'],
                'sourceName' => (string) $row['source_name'],
                'stage' => ['id' => (string) $row['production_stage_id'], 'label' => (string) $row['stage_label'], 'ordinal' => (int) $row['ordinal']],
                'completed' => $row['production_completed_at'] !== null,
                'unavailable' => $row['operational_status'] === 'unavailable',
                'productionVersion' => (int) $row['production_version'],
                'owner' => $row['owner_name'] === null ? null : ['displayName' => (string) $row['owner_name'], 'department' => $row['owner_department'], 'since' => ExceptionQueries::iso($row['production_claimed_at'])],
                'openExceptionId' => $row['open_exception_uuid'],
                'arrivalNumber' => $quality['arrivalNumber'],
                'reworkCycles' => $quality['reworkCycles'],
                'repeatedErrors' => $quality['repeatedErrors'],
                'items' => array_map(static fn (array $item): array => [
                    'id' => (string) $item['item_uuid'],
                    'lineNumber' => (int) $item['line_number'],
                    'name' => (string) $item['name'],
                    'code' => $item['product_code'],
                    'variant' => $item['variant'],
                    'color' => $item['color'],
                    'meters' => $item['meters'] === null ? null : (string) $item['meters'],
                    'quantity' => (int) $item['quantity'],
                ], $items->fetchAll(PDO::FETCH_ASSOC)),
            ],
            'exceptions' => $this->exceptions->orderHistory((string) $row['order_uuid']),
        ];
    }

    private function requireAccess(EmployeeIdentity $actor): void
    {
        $this->authorization->requireApplication($actor, 'dashboard');
        if (!$this->approvers->canLookup($actor)) {
            throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Permission denied.');
        }
    }

    private function limit(EmployeeIdentity $actor): void
    {
        if (!$this->rateLimiter->hit('management-order-lookup', $actor->employeeUuid, self::LIMIT, self::WINDOW_SECONDS)) {
            throw new ApiException(429, 'RATE_LIMITED', 'Too many lookups. Try again shortly.');
        }
    }
}
