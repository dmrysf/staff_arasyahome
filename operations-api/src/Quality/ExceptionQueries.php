<?php

declare(strict_types=1);

namespace Arasya\Operations\Quality;

use Arasya\Operations\Http\ApiException;
use PDO;

/**
 * Reads of production exceptions. Nothing here writes. Authorization is decided by the caller
 * (involvement for Staff, ApproverPolicy for the Dashboard); these methods only shape data.
 *
 * Views never contain customer data (the operational order has none) and the quality history is
 * internal: customer-facing surfaces must never call this class.
 */
final readonly class ExceptionQueries
{
    public const OPEN_STATUSES = ['awaiting_acknowledgment', 'awaiting_approval'];
    private const SUMMARY = "SELECT x.exception_uuid, x.exception_number, x.status, x.version, x.global_order_id, x.source_key, s.display_name AS source_name,
            x.order_number_snapshot, x.reason_key, x.reason_label_snapshot, x.line_count, x.fault_meters, x.arrival_number, x.rework_cycle,
            x.reported_at, x.acknowledged_at, x.resolved_at, x.detector_employee_uuid, x.responsible_employee_uuid,
            de.display_name AS detector_name, re.display_name AS responsible_name,
            (SELECT d.opened_at FROM production_exception_decisions d WHERE d.exception_uuid = x.exception_uuid AND d.status = 'pending' LIMIT 1) AS pending_since,
            (SELECT MAX(d.attempt_number) FROM production_exception_decisions d WHERE d.exception_uuid = x.exception_uuid) AS attempts,
            (SELECT COUNT(*) FROM production_exceptions p WHERE p.order_uuid = x.order_uuid AND p.rework_cycle IS NOT NULL AND p.exception_uuid <> x.exception_uuid) AS previous_cycles
        FROM production_exceptions x
        INNER JOIN order_sources s ON s.source_key = x.source_key
        INNER JOIN employees de ON de.employee_uuid = x.detector_employee_uuid
        INNER JOIN employees re ON re.employee_uuid = x.responsible_employee_uuid";

    public function __construct(private PDO $pdo)
    {
    }

    public static function iso(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return substr(str_replace(' ', 'T', $value), 0, 23) . 'Z';
    }

    public static function number(int $number): string
    {
        return sprintf('EX-%06d', $number);
    }

    /** Exceptions in which the employee is the detector or the responsible employee: open ones and the last 14 days. @return list<array<string, mixed>> */
    public function staffList(string $employeeUuid, string $since): array
    {
        $statement = $this->pdo->prepare(self::SUMMARY . "
            WHERE (x.detector_employee_uuid = :employee OR x.responsible_employee_uuid = :employee_again)
              AND (x.status IN ('awaiting_acknowledgment', 'awaiting_approval') OR x.updated_at >= :since)
            ORDER BY x.updated_at DESC, x.exception_number DESC LIMIT 50");
        $statement->execute(['employee' => $employeeUuid, 'employee_again' => $employeeUuid, 'since' => $since]);
        return array_map(fn (array $row): array => $this->summary($row, $employeeUuid), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function managerList(string $view, string $actorUuid): array
    {
        [$where, $order, $params] = match ($view) {
            'pending' => ["x.status = 'awaiting_approval'", 'pending_since ASC, x.exception_number ASC', []],
            'waiting' => ["x.status = 'awaiting_acknowledgment'", 'x.reported_at ASC, x.exception_number ASC', []],
            'mine' => ['EXISTS (SELECT 1 FROM production_exception_decisions md WHERE md.exception_uuid = x.exception_uuid AND md.decided_by_employee_uuid = :actor)', 'x.updated_at DESC, x.exception_number DESC', ['actor' => $actorUuid]],
            default => throw new ApiException(400, 'INVALID_REQUEST', 'Unknown list.'),
        };
        $statement = $this->pdo->prepare(self::SUMMARY . " WHERE {$where} ORDER BY {$order} LIMIT 200");
        $statement->execute($params);
        $items = array_map(fn (array $row): array => $this->summary($row, null), $statement->fetchAll(PDO::FETCH_ASSOC));
        if ($view === 'mine') {
            $decisions = $this->pdo->prepare("SELECT exception_uuid, attempt_number, status, decided_at, decision_comment FROM production_exception_decisions WHERE decided_by_employee_uuid = ? ORDER BY decided_at");
            $decisions->execute([$actorUuid]);
            $mine = [];
            foreach ($decisions->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $mine[(string) $row['exception_uuid']][] = ['attempt' => (int) $row['attempt_number'], 'status' => (string) $row['status'], 'decidedAt' => self::iso((string) $row['decided_at']), 'comment' => $row['decision_comment']];
            }
            foreach ($items as &$item) {
                $item['myDecisions'] = $mine[$item['id']] ?? [];
            }
        }
        return $items;
    }

    /** @return array<string, mixed>|null */
    public function find(string $exceptionUuid): ?array
    {
        $statement = $this->pdo->prepare(self::SUMMARY . ' WHERE x.exception_uuid = ?');
        $statement->execute([$exceptionUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** Full view for an involved Staff employee or a Dashboard approver. @return array<string, mixed> */
    public function detail(string $exceptionUuid, ?string $viewerUuid, bool $manager): array
    {
        $row = $this->find($exceptionUuid) ?? throw new ApiException(404, 'EXCEPTION_NOT_FOUND', 'The request was not found.');
        $detail = $this->summary($row, $viewerUuid);
        $extra = $this->pdo->prepare('SELECT detector_comment, acknowledgment_comment, qr_verified_at, order_uuid, cancel_reason FROM production_exceptions WHERE exception_uuid = ?');
        $extra->execute([$exceptionUuid]);
        $more = $extra->fetch(PDO::FETCH_ASSOC);
        $detail['detectorComment'] = $more['detector_comment'];
        $detail['acknowledgmentComment'] = $more['acknowledgment_comment'];
        $detail['qrVerifiedAt'] = self::iso($more['qr_verified_at']);
        $detail['cancelReason'] = $more['cancel_reason'];

        $lines = $this->pdo->prepare('SELECT item_uuid, line_number, name_snapshot, product_code_snapshot, variant_snapshot, color_snapshot, quantity_snapshot, meters_snapshot FROM production_exception_lines WHERE exception_uuid = ? ORDER BY line_number');
        $lines->execute([$exceptionUuid]);
        $detail['lines'] = array_map(static fn (array $line): array => [
            'itemId' => (string) $line['item_uuid'],
            'lineNumber' => (int) $line['line_number'],
            'name' => (string) $line['name_snapshot'],
            'code' => $line['product_code_snapshot'],
            'variant' => $line['variant_snapshot'],
            'color' => $line['color_snapshot'],
            'quantity' => (int) $line['quantity_snapshot'],
            'meters' => (string) $line['meters_snapshot'],
        ], $lines->fetchAll(PDO::FETCH_ASSOC));
        $detail['decisions'] = $this->decisions($exceptionUuid);
        if ($manager) {
            $detail['timeline'] = $this->timeline($exceptionUuid);
            $detail['orderHistory'] = $this->orderHistory((string) $more['order_uuid']);
        }
        return $detail;
    }

    /** Every exception of one order, oldest first. @return list<array<string, mixed>> */
    public function orderHistory(string $orderUuid): array
    {
        $statement = $this->pdo->prepare(self::SUMMARY . ' WHERE x.order_uuid = ? ORDER BY x.exception_number');
        $statement->execute([$orderUuid]);
        return array_map(function (array $row): array {
            $summary = $this->summary($row, null);
            $summary['decisions'] = $this->decisions((string) $row['exception_uuid']);
            return $summary;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Internal quality facts of one order for the Staff order view: which arrival at tailoring intake
     * this is, how many approved rework cycles happened, and the open exception (if any).
     *
     * @return array<string, mixed>
     */
    public function orderQuality(string $orderUuid, ?string $openExceptionUuid, string $viewerUuid): array
    {
        $arrivals = $this->pdo->prepare("SELECT COUNT(*) FROM order_activity_events WHERE order_uuid = ? AND action = 'stage_completed' AND from_stage_id = 'material-preparation' AND to_stage_id = 'workshop-receiving'");
        $arrivals->execute([$orderUuid]);
        $cycles = $this->pdo->prepare('SELECT COUNT(*) FROM production_exceptions WHERE order_uuid = ? AND rework_cycle IS NOT NULL');
        $cycles->execute([$orderUuid]);
        $reworkCycles = (int) $cycles->fetchColumn();
        $open = null;
        if ($openExceptionUuid !== null) {
            $row = $this->find($openExceptionUuid);
            if ($row !== null) {
                $open = $this->summary($row, $viewerUuid);
            }
        }
        return [
            'arrivalNumber' => (int) $arrivals->fetchColumn(),
            'reworkCycles' => $reworkCycles,
            'repeatedErrors' => $reworkCycles >= 2,
            'openException' => $open,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function decisions(string $exceptionUuid): array
    {
        $statement = $this->pdo->prepare(
            'SELECT d.attempt_number, d.status, d.opened_reason, d.opened_comment, d.opened_at, d.decided_at, d.decision_comment, d.decided_via,
                    ob.display_name AS opened_by_name, db.display_name AS decided_by_name, d.decided_by_employee_uuid
             FROM production_exception_decisions d
             INNER JOIN employees ob ON ob.employee_uuid = d.opened_by_employee_uuid
             LEFT JOIN employees db ON db.employee_uuid = d.decided_by_employee_uuid
             WHERE d.exception_uuid = ? ORDER BY d.attempt_number',
        );
        $statement->execute([$exceptionUuid]);
        return array_map(static fn (array $row): array => [
            'attempt' => (int) $row['attempt_number'],
            'status' => (string) $row['status'],
            'openedReason' => (string) $row['opened_reason'],
            'openedBy' => (string) $row['opened_by_name'],
            'openedComment' => $row['opened_comment'],
            'openedAt' => self::iso((string) $row['opened_at']),
            'decidedAt' => self::iso($row['decided_at']),
            'decidedBy' => $row['decided_by_name'],
            'decidedById' => $row['decided_by_employee_uuid'],
            'decidedVia' => $row['decided_via'],
            'comment' => $row['decision_comment'],
            'waitSeconds' => $row['decided_at'] === null ? null : self::seconds((string) $row['opened_at'], (string) $row['decided_at']),
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    private function timeline(string $exceptionUuid): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ev.action, ev.occurred_at, ev.exception_version_after, ev.metadata_json, e.display_name
             FROM production_exception_events ev INNER JOIN employees e ON e.employee_uuid = ev.actor_employee_uuid
             WHERE ev.exception_uuid = ? ORDER BY ev.exception_version_after',
        );
        $statement->execute([$exceptionUuid]);
        return array_map(static function (array $row): array {
            $metadata = $row['metadata_json'] === null ? null : json_decode((string) $row['metadata_json'], true);
            return ['action' => (string) $row['action'], 'actor' => (string) $row['display_name'], 'at' => self::iso((string) $row['occurred_at']), 'version' => (int) $row['exception_version_after'], 'details' => is_array($metadata) ? $metadata : null];
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function summary(array $row, ?string $viewerUuid): array
    {
        $previousCycles = (int) $row['previous_cycles'];
        $summary = [
            'id' => (string) $row['exception_uuid'],
            'number' => self::number((int) $row['exception_number']),
            'type' => 'cutting_fault',
            'status' => (string) $row['status'],
            'version' => (int) $row['version'],
            'order' => ['id' => (string) $row['global_order_id'], 'orderNumber' => (string) $row['order_number_snapshot'], 'source' => (string) $row['source_key'], 'sourceName' => (string) $row['source_name']],
            'reason' => ['key' => (string) $row['reason_key'], 'label' => (string) $row['reason_label_snapshot']],
            'lineCount' => (int) $row['line_count'],
            'faultMeters' => (string) $row['fault_meters'],
            'arrivalNumber' => (int) $row['arrival_number'],
            'reworkCycle' => $row['rework_cycle'] === null ? null : (int) $row['rework_cycle'],
            'repeatedError' => $previousCycles >= 1,
            'detector' => ['displayName' => (string) $row['detector_name']],
            'responsible' => ['displayName' => (string) $row['responsible_name']],
            'reportedAt' => self::iso((string) $row['reported_at']),
            'acknowledgedAt' => self::iso($row['acknowledged_at']),
            'resolvedAt' => self::iso($row['resolved_at']),
            'pendingSince' => self::iso($row['pending_since']),
            'attempts' => (int) ($row['attempts'] ?? 0),
        ];
        if ($viewerUuid !== null) {
            $isResponsible = $row['responsible_employee_uuid'] === $viewerUuid;
            $isDetector = $row['detector_employee_uuid'] === $viewerUuid;
            $summary['role'] = $isResponsible ? 'responsible' : ($isDetector ? 'detector' : null);
            $summary['actions'] = [
                'canAcknowledge' => $isResponsible && $row['status'] === 'awaiting_acknowledgment',
                'canRequestRereview' => ($isResponsible || $isDetector) && $row['status'] === 'rejected',
            ];
        }
        return $summary;
    }

    private static function seconds(string $from, string $to): int
    {
        $a = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $from, new \DateTimeZone('UTC'));
        $b = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $to, new \DateTimeZone('UTC'));
        return $a === false || $b === false ? 0 : max(0, $b->getTimestamp() - $a->getTimestamp());
    }
}
