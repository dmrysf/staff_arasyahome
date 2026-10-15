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
    /** Statuses that block the order. `rejected` (intake flow) does not; `return_rejected` and `awaiting_restore_receipt` (supervised flow) do. */
    public const OPEN_STATUSES = ['awaiting_acknowledgment', 'awaiting_approval', 'awaiting_supervisor_review', 'in_supervisor_custody', 'return_rejected', 'awaiting_restore_receipt'];
    /** Supervisor tabs: De verificat, În grija mea, De returnat la tăiere, Finalizate. */
    public const SUPERVISOR_VIEWS = [
        'review' => ['awaiting_supervisor_review'],
        'custody' => ['in_supervisor_custody', 'return_rejected', 'awaiting_restore_receipt'],
        'return' => ['awaiting_acknowledgment', 'awaiting_approval'],
        'done' => ['dismissed', 'approved', 'restored', 'cancelled'],
    ];
    private const SUMMARY = "SELECT x.exception_uuid, x.exception_number, x.exception_type, x.status, x.version, x.global_order_id, x.source_key, s.display_name AS source_name,
            x.order_number_snapshot, x.reason_key, x.reason_label_snapshot, x.line_count, x.fault_meters, x.unmeasured_line_count, x.arrival_number, x.rework_cycle,
            x.reported_at, x.acknowledged_at, x.resolved_at, x.detector_employee_uuid, x.responsible_employee_uuid,
            x.detected_stage_id, ds.display_name AS detected_stage_label, x.detected_owner_employee_uuid, x.supervisor_employee_uuid, x.supervisor_reviewed_at,
            x.confirmed_reason_key, x.confirmed_reason_label_snapshot, cr.cutting_attributable AS confirmed_reason_attributable, x.return_requested_at,
            x.receiver_employee_uuid, x.physical_custodian_employee_uuid, x.responsibility_position, x.attribution,
            x.restore_recipient_employee_uuid, x.restore_requested_at, x.restore_received_at, x.cancelled_by_employee_uuid,
            de.display_name AS detector_name, re.display_name AS responsible_name, se.display_name AS supervisor_name, rce.display_name AS receiver_name, pce.display_name AS custodian_name,
            rre.display_name AS restore_recipient_name,
            (SELECT d.opened_at FROM production_exception_decisions d WHERE d.exception_uuid = x.exception_uuid AND d.status = 'pending' LIMIT 1) AS pending_since,
            (SELECT MAX(d.attempt_number) FROM production_exception_decisions d WHERE d.exception_uuid = x.exception_uuid) AS attempts,
            (SELECT COUNT(*) FROM production_exceptions p WHERE p.order_uuid = x.order_uuid AND p.rework_cycle IS NOT NULL AND p.exception_uuid <> x.exception_uuid) AS previous_cycles,
            (SELECT COUNT(*) FROM operational_order_items i WHERE i.order_uuid = x.order_uuid) AS order_line_count,
            (SELECT COALESCE(SUM(i.quantity), 0) FROM operational_order_items i WHERE i.order_uuid = x.order_uuid) AS order_quantity,
            (SELECT COALESCE(SUM(l.quantity_snapshot), 0) FROM production_exception_lines l WHERE l.exception_uuid = x.exception_uuid) AS affected_quantity,
            sc.exception_uuid AS scope_recorded, sc.track_uuid AS scope_track_uuid, sc.partial AS scope_partial, sc.affected_quantity AS scope_affected_quantity,
            sc.carrier_quantity_at_report AS scope_carrier_quantity, sc.order_quantity_at_report AS scope_order_quantity
        FROM production_exceptions x
        LEFT JOIN production_exception_scopes sc ON sc.exception_uuid = x.exception_uuid
        INNER JOIN order_sources s ON s.source_key = x.source_key
        INNER JOIN employees de ON de.employee_uuid = x.detector_employee_uuid
        INNER JOIN employees re ON re.employee_uuid = x.responsible_employee_uuid
        LEFT JOIN employees se ON se.employee_uuid = x.supervisor_employee_uuid
        LEFT JOIN employees rce ON rce.employee_uuid = x.receiver_employee_uuid
        LEFT JOIN employees pce ON pce.employee_uuid = x.physical_custodian_employee_uuid
        LEFT JOIN employees rre ON rre.employee_uuid = x.restore_recipient_employee_uuid
        LEFT JOIN production_stages ds ON ds.stage_id = x.detected_stage_id
        LEFT JOIN production_fault_reasons cr ON cr.reason_key = x.confirmed_reason_key";

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

    /**
     * Exceptions in which the employee is the detector, the receiver, the restore recipient or the responsible
     * employee: open ones and the last 14 days. A supervised case reaches the responsible cutting employee only
     * once the supervisor sent the return; before that it is internal to tailoring. @return list<array<string, mixed>>
     */
    public function staffList(string $employeeUuid, string $since): array
    {
        $open = "'" . implode("', '", self::OPEN_STATUSES) . "'";
        $statement = $this->pdo->prepare(self::SUMMARY . "
            WHERE (x.detector_employee_uuid = :employee OR x.receiver_employee_uuid = :employee_receiver OR x.restore_recipient_employee_uuid = :employee_recipient
                   OR (x.responsible_employee_uuid = :employee_again AND (x.exception_type = 'cutting_fault' OR x.return_requested_at IS NOT NULL)))
              AND (x.status IN ({$open}) OR x.updated_at >= :since)
            ORDER BY x.updated_at DESC, x.exception_number DESC LIMIT 50");
        $statement->execute(['employee' => $employeeUuid, 'employee_receiver' => $employeeUuid, 'employee_recipient' => $employeeUuid, 'employee_again' => $employeeUuid, 'since' => $since]);
        return array_map(fn (array $row): array => $this->summary($row, $employeeUuid), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Supervised cases of the given sources for one supervisor tab. @param list<string> $sources @return list<array<string, mixed>> */
    public function supervisorList(string $view, array $sources, string $viewerUuid, string $since): array
    {
        $statuses = self::SUPERVISOR_VIEWS[$view] ?? throw new ApiException(400, 'INVALID_REQUEST', 'Unknown list.');
        if ($sources === []) {
            return [];
        }
        $statusList = "'" . implode("', '", $statuses) . "'";
        $sourceMarks = implode(', ', array_fill(0, count($sources), '?'));
        $statement = $this->pdo->prepare(self::SUMMARY . " WHERE x.exception_type = 'tailoring_defect' AND x.status IN ({$statusList}) AND x.source_key IN ({$sourceMarks})"
            . ($view === 'done' ? ' AND x.updated_at >= ?' : '') . ' ORDER BY ' . ($view === 'done' ? 'x.updated_at DESC' : 'x.reported_at ASC') . ', x.exception_number ASC LIMIT 200');
        $statement->execute([...$sources, ...($view === 'done' ? [$since] : [])]);
        return array_map(fn (array $row): array => $this->summary($row, $viewerUuid, true), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Raw exception row for authorization decisions. @return array<string, mixed>|null */
    public function raw(string $exceptionUuid): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM production_exceptions WHERE exception_uuid = ?');
        $statement->execute([$exceptionUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
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

    /** One request as the given employee sees it (role and granted actions), or null. @return array<string, mixed>|null */
    public function viewerSummary(string $exceptionUuid, string $viewerUuid): ?array
    {
        $row = $this->find($exceptionUuid);
        return $row === null ? null : $this->summary($row, $viewerUuid);
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
    public function detail(string $exceptionUuid, ?string $viewerUuid, bool $manager, bool $supervisorView = false): array
    {
        $row = $this->find($exceptionUuid) ?? throw new ApiException(404, 'EXCEPTION_NOT_FOUND', 'The request was not found.');
        $detail = $this->summary($row, $viewerUuid, $supervisorView);
        $extra = $this->pdo->prepare('SELECT detector_comment, acknowledgment_comment, qr_verified_at, order_uuid, cancel_reason, supervisor_comment FROM production_exceptions WHERE exception_uuid = ?');
        $extra->execute([$exceptionUuid]);
        $more = $extra->fetch(PDO::FETCH_ASSOC);
        $detail['detectorComment'] = $more['detector_comment'];
        $detail['acknowledgmentComment'] = $more['acknowledgment_comment'];
        $detail['qrVerifiedAt'] = self::iso($more['qr_verified_at']);
        $detail['cancelReason'] = $more['cancel_reason'];
        $detail['supervisorComment'] = $more['supervisor_comment'];

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
            // NULL: the source sent no meters for this line; never shown as a measured zero.
            'meters' => $line['meters_snapshot'] === null ? null : (string) $line['meters_snapshot'],
        ], $lines->fetchAll(PDO::FETCH_ASSOC));
        $detail['affectedPieces'] = $this->affectedPieces($exceptionUuid);
        $detail['administrativeRecovery'] = $this->recoveryLinks($exceptionUuid);
        if ($detail['orderScope']['piecesRecorded']) {
            $perLine = array_count_values(array_column($detail['affectedPieces'], 'itemId'));
            foreach ($detail['lines'] as &$line) {
                $line['affectedQuantity'] = $perLine[$line['itemId']] ?? 0;
            }
            unset($line);
        }
        $detail['decisions'] = $this->decisions($exceptionUuid);
        if ($row['exception_type'] === 'tailoring_defect') {
            // Everyone allowed to open a supervised case sees its timeline and the cutting handoff it refers to.
            $detail['timeline'] = $this->timeline($exceptionUuid);
            $detail['cuttingHandoff'] = $this->handoff($exceptionUuid);
            $detail['currentStage'] = $this->currentStage((string) $more['order_uuid'], $row['scope_track_uuid'] === null ? null : (string) $row['scope_track_uuid']);
        }
        if ($manager) {
            $detail['detector']['id'] = (string) $row['detector_employee_uuid'];
            $detail['responsible']['id'] = (string) $row['responsible_employee_uuid'];
            $detail['timeline'] = $this->timeline($exceptionUuid);
            $detail['orderHistory'] = $this->orderHistory((string) $more['order_uuid']);
            $detail['accountability'] = $this->accountability($row);
        }
        return $detail;
    }

    /**
     * One accountability record per decided attempt, for managers and audit: who reported, who was
     * responsible, who decided (identity UUID and display label snapshot of the IAM audit event), through
     * which authority, when (server time), the IAM audit reference and the resulting production
     * transition. Display names are labels only; the UUIDs are the accountable identities.
     *
     * @param array<string, mixed> $row a SUMMARY row
     * @return list<array<string, mixed>>
     */
    private function accountability(array $row): array
    {
        $statement = $this->pdo->prepare(
            "SELECT d.attempt_number, d.status, d.decided_at, d.decided_via, d.decided_by_employee_uuid, d.decision_comment, decider.display_name AS decided_by_name,
                    a.event_id AS audit_event_id, a.actor_label AS audit_actor_label, a.request_id AS audit_request_id
             FROM production_exception_decisions d
             LEFT JOIN employees decider ON decider.employee_uuid = d.decided_by_employee_uuid
             LEFT JOIN iam_audit_events a ON a.target_type = 'production_exception' AND a.target_id = d.exception_uuid
                AND a.action IN ('production.exception.approved', 'production.exception.rejected')
                AND a.actor_employee_uuid = d.decided_by_employee_uuid AND a.created_at = d.decided_at
             WHERE d.exception_uuid = ? AND d.decided_at IS NOT NULL ORDER BY d.attempt_number",
        );
        $statement->execute([$row['exception_uuid']]);
        $stages = $this->pdo->query("SELECT stage_id, display_name FROM production_stages WHERE stage_id IN ('workshop-receiving', 'material-preparation')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $stage = static fn (string $id): array => ['id' => $id, 'label' => isset($stages[$id]) ? (string) $stages[$id] : null];
        return array_map(static fn (array $decision): array => [
            'attempt' => (int) $decision['attempt_number'],
            'order' => ['id' => (string) $row['global_order_id'], 'orderNumber' => (string) $row['order_number_snapshot'], 'source' => (string) $row['source_key']],
            'stage' => $stage(CuttingFaultService::DETECTION_STAGE),
            'requestedAction' => ['type' => 'return_to_cutting', 'from' => $stage(CuttingFaultService::DETECTION_STAGE), 'to' => $stage(CuttingFaultService::RETURN_STAGE)],
            'reason' => ['key' => (string) $row['reason_key'], 'label' => (string) $row['reason_label_snapshot']],
            'reportedBy' => ['id' => (string) $row['detector_employee_uuid'], 'displayName' => (string) $row['detector_name']],
            'responsible' => ['id' => (string) $row['responsible_employee_uuid'], 'displayName' => (string) $row['responsible_name']],
            'decidedBy' => [
                'id' => $decision['decided_by_employee_uuid'] === null ? null : (string) $decision['decided_by_employee_uuid'],
                // The label recorded with the decision; the current name only when no audit label exists.
                'displayName' => $decision['audit_actor_label'] !== null ? (string) $decision['audit_actor_label'] : ($decision['decided_by_name'] === null ? null : (string) $decision['decided_by_name']),
            ],
            'via' => $decision['decided_via'] === null ? null : (string) $decision['decided_via'],
            'decision' => (string) $decision['status'],
            'comment' => $decision['decision_comment'],
            'decidedAt' => self::iso((string) $decision['decided_at']),
            'auditEventId' => $decision['audit_event_id'] === null ? null : (string) $decision['audit_event_id'],
            'requestId' => $decision['audit_request_id'] === null ? null : (string) $decision['audit_request_id'],
            'transition' => $decision['status'] === 'approved' ? ['from' => CuttingFaultService::DETECTION_STAGE, 'to' => CuttingFaultService::RETURN_STAGE] : null,
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
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

    /** The cutting handoff the responsibility was derived from: who completed it, when, which arrival. @return array<string, mixed>|null */
    private function handoff(string $exceptionUuid): ?array
    {
        // A re-cut piece: the responsibility refers to the cutting handoff on its rework track.
        $recut = $this->pdo->prepare(
            'SELECT t.occurred_at, JSON_UNQUOTE(JSON_EXTRACT(t.metadata_json, \'$.meters\')) AS meters_snapshot, e.display_name, x.arrival_number FROM production_exception_scopes sc
             INNER JOIN production_track_events t ON t.event_uuid = sc.responsible_track_event_uuid
             INNER JOIN production_exceptions x ON x.exception_uuid = sc.exception_uuid
             INNER JOIN employees e ON e.employee_uuid = t.employee_uuid WHERE sc.exception_uuid = ?',
        );
        $recut->execute([$exceptionUuid]);
        $row = $recut->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return ['employee' => (string) $row['display_name'], 'at' => self::iso((string) $row['occurred_at']), 'meters' => $row['meters_snapshot'], 'arrivalNumber' => (int) $row['arrival_number'], 'rework' => true];
        }
        $statement = $this->pdo->prepare(
            'SELECT a.occurred_at, a.meters_snapshot, e.display_name, x.arrival_number FROM production_exceptions x
             INNER JOIN order_activity_events a ON a.event_id = x.responsible_activity_event_id
             INNER JOIN employees e ON e.employee_uuid = a.employee_uuid WHERE x.exception_uuid = ?',
        );
        $statement->execute([$exceptionUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? ['employee' => (string) $row['display_name'], 'at' => self::iso((string) $row['occurred_at']), 'meters' => $row['meters_snapshot'], 'arrivalNumber' => (int) $row['arrival_number']] : null;
    }

    /** @return array{id: string, label: string|null} */
    private function currentStage(string $orderUuid, ?string $trackUuid = null): array
    {
        // For a report on pieces in rework, the stage of those pieces (their track), not of the rest of the order.
        $statement = $trackUuid === null
            ? $this->pdo->prepare('SELECT o.production_stage_id, s.display_name FROM operational_orders o LEFT JOIN production_stages s ON s.stage_id = o.production_stage_id WHERE o.order_uuid = ?')
            : $this->pdo->prepare('SELECT t.stage_id AS production_stage_id, s.display_name FROM production_tracks t LEFT JOIN production_stages s ON s.stage_id = t.stage_id WHERE t.track_uuid = ?');
        $statement->execute([$trackUuid ?? $orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return ['id' => (string) ($row['production_stage_id'] ?? ''), 'label' => isset($row['display_name']) ? (string) $row['display_name'] : null];
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
    private function summary(array $row, ?string $viewerUuid, bool $supervisorView = false): array
    {
        $previousCycles = (int) $row['previous_cycles'];
        $tailoring = $row['exception_type'] === 'tailoring_defect';
        $person = static fn (mixed $name): ?array => $name === null ? null : ['displayName' => (string) $name];
        $summary = [
            'id' => (string) $row['exception_uuid'],
            'number' => self::number((int) $row['exception_number']),
            'type' => (string) $row['exception_type'],
            'status' => (string) $row['status'],
            'version' => (int) $row['version'],
            'order' => ['id' => (string) $row['global_order_id'], 'orderNumber' => (string) $row['order_number_snapshot'], 'source' => (string) $row['source_key'], 'sourceName' => (string) $row['source_name']],
            'reason' => ['key' => (string) $row['reason_key'], 'label' => (string) $row['reason_label_snapshot']],
            'lineCount' => (int) $row['line_count'],
            // Sum of the measured lines; null when no line was measured. unmeasuredLineCount lines have no known meters.
            'faultMeters' => (int) $row['unmeasured_line_count'] === (int) $row['line_count'] ? null : (string) $row['fault_meters'],
            'unmeasuredLineCount' => (int) $row['unmeasured_line_count'],
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
            'detectedStage' => ['id' => (string) $row['detected_stage_id'], 'label' => $row['detected_stage_label'] === null ? null : (string) $row['detected_stage_label']],
            'confirmedReason' => $row['confirmed_reason_key'] === null ? null : ['key' => (string) $row['confirmed_reason_key'], 'label' => (string) $row['confirmed_reason_label_snapshot'], 'cuttingAttributable' => (int) $row['confirmed_reason_attributable'] === 1],
            'supervisor' => $person($row['supervisor_name']),
            'receiver' => $person($row['receiver_name']),
            'physicalCustodian' => $person($row['custodian_name']),
            'responsibilityPosition' => $row['responsibility_position'],
            'attribution' => $row['attribution'],
            'supervisorReviewedAt' => self::iso($row['supervisor_reviewed_at']),
            'returnRequestedAt' => self::iso($row['return_requested_at']),
            'restoreRecipient' => $person($row['restore_recipient_name']),
            'restoreRequestedAt' => self::iso($row['restore_requested_at']),
            'restoreReceivedAt' => self::iso($row['restore_received_at']),
            'cancelledByRoot' => $row['cancelled_by_employee_uuid'] !== null,
            'orderScope' => self::scope($row),
        ];
        if ($viewerUuid !== null) {
            $isResponsible = $row['responsible_employee_uuid'] === $viewerUuid;
            $isDetector = $row['detector_employee_uuid'] === $viewerUuid;
            $isReceiver = $row['receiver_employee_uuid'] === $viewerUuid;
            $isRecipient = $row['restore_recipient_employee_uuid'] === $viewerUuid;
            $supervises = $tailoring && $supervisorView;
            $status = (string) $row['status'];
            $summary['role'] = $isResponsible ? 'responsible' : ($isReceiver ? 'receiver' : ($isDetector ? 'detector' : ($isRecipient ? 'recipient' : ($supervises ? 'supervisor' : null))));
            $summary['actions'] = [
                'canAcknowledge' => $status === 'awaiting_acknowledgment' && ($tailoring ? $isReceiver : $isResponsible),
                'statesResponsibility' => $tailoring && $isReceiver && $isResponsible,
                'canRequestRereview' => !$tailoring && ($isResponsible || $isDetector) && $status === 'rejected',
                'canReview' => $supervises && !$isDetector && $status === 'awaiting_supervisor_review',
                'canRequestReturn' => $supervises && $status === 'in_supervisor_custody',
                'canRestore' => $supervises && (in_array($status, ['in_supervisor_custody', 'awaiting_acknowledgment', 'return_rejected'], true) || ($status === 'awaiting_restore_receipt' && $row['cancelled_by_employee_uuid'] === null)),
                'canConfirmRestore' => $tailoring && $isRecipient && $status === 'awaiting_restore_receipt',
                'canAskManagersAgain' => $supervises && $status === 'return_rejected',
                'canAddNote' => $tailoring && in_array($status, self::OPEN_STATUSES, true) && ($supervises || $isDetector || $isReceiver || $isRecipient || ($isResponsible && $row['return_requested_at'] !== null)),
            ];
        }
        return $summary;
    }

    /**
     * What an approval moves. A report that named exact pieces (Staff 2.26.0+) acts on its carrier: only the reported
     * pieces when they were some of the carrier's pieces (partial: they travel on their own track), the whole order
     * when they were every piece of it. An older report (no scope row) named whole lines and moves the whole order.
     *
     * @param array<string, mixed> $row @return array<string, mixed>
     */
    private static function scope(array $row): array
    {
        $recorded = $row['scope_recorded'] !== null;
        $partial = $recorded && (int) $row['scope_partial'] === 1;
        $affected = $recorded ? (int) $row['scope_affected_quantity'] : (int) $row['affected_quantity'];
        // API 2.32.0: a recorded scope counts physical pieces (the confirmed plan), an older report commercial units.
        $total = $recorded ? (int) $row['scope_order_quantity'] : (int) $row['order_quantity'];
        $moving = !$recorded ? $total : ($partial ? $affected : (int) $row['scope_carrier_quantity']);
        return [
            'orderLineCount' => (int) $row['order_line_count'],
            'orderQuantity' => $total,
            'quantityUnit' => $recorded ? 'physical_pieces' : 'commercial_units',
            'commercialOrderQuantity' => (int) $row['order_quantity'],
            'affectedLineCount' => (int) $row['line_count'],
            'affectedQuantity' => $affected,
            'movingQuantity' => $moving,
            'unaffectedQuantity' => max(0, $total - $affected),
            'wholeOrderMoves' => $moving >= $total,
            'partial' => $partial,
            'piecesRecorded' => $recorded,
            'carrier' => $recorded && $row['scope_track_uuid'] !== null ? 'track' : 'order',
            'trackId' => $recorded && $row['scope_track_uuid'] !== null ? (string) $row['scope_track_uuid'] : null,
        ];
    }

    /** The exact pieces a report named, with their line facts and label (Bucata i/n). @return list<array<string, mixed>> */
    public function affectedPieces(string $exceptionUuid): array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.unit_uuid, u.item_uuid, u.unit_index, u.unit_count_snapshot, u.rework_cycle_at_report, l.line_number, l.name_snapshot, l.product_code_snapshot, l.color_snapshot,
                    i.width_value, i.height_value, i.measurement_unit, w.track_uuid, w.status AS unit_status,
                    pp.width_value AS piece_width, pp.height_value AS piece_height, pp.measurement_unit AS piece_unit, pp.fabric_length, pp.measurement_source, pp.fabric_source,
                    lp.product_type, lp.revision AS plan_revision, rp.replacement_unit_uuid AS replaced_by
             FROM production_exception_units u
             LEFT JOIN production_exception_lines l ON l.exception_uuid = u.exception_uuid AND l.item_uuid = u.item_uuid
             LEFT JOIN operational_order_items i ON i.item_uuid = u.item_uuid
             LEFT JOIN production_work_units w ON w.unit_uuid = u.unit_uuid
             LEFT JOIN production_line_plan_heads h ON h.item_uuid = u.item_uuid
             LEFT JOIN production_line_plans lp ON lp.plan_uuid = h.plan_uuid
             LEFT JOIN production_plan_pieces pp ON pp.plan_uuid = h.plan_uuid AND pp.piece_index = u.unit_index
             LEFT JOIN production_unit_replacements rp ON rp.original_unit_uuid = u.unit_uuid
             WHERE u.exception_uuid = ? ORDER BY l.line_number, u.unit_index',
        );
        $statement->execute([$exceptionUuid]);
        return array_map(static fn (array $row): array => [
            'unitId' => (string) $row['unit_uuid'],
            'itemId' => (string) $row['item_uuid'],
            'piece' => (int) $row['unit_index'] . '/' . (int) $row['unit_count_snapshot'],
            'index' => (int) $row['unit_index'],
            'count' => (int) $row['unit_count_snapshot'],
            'lineNumber' => $row['line_number'] === null ? null : (int) $row['line_number'],
            'name' => $row['name_snapshot'],
            'code' => $row['product_code_snapshot'],
            'color' => $row['color_snapshot'],
            // Decimal strings, exactly as stored (a replayed response must equal the original one). The commercial line's
            // finished size; the cutting size of this piece is pieceMeasurements (null: unknown, must be verified).
            'measurements' => $row['width_value'] === null && $row['height_value'] === null ? null : ['width' => $row['width_value'] === null ? null : (string) $row['width_value'], 'height' => $row['height_value'] === null ? null : (string) $row['height_value'], 'unit' => $row['measurement_unit']],
            'pieceMeasurements' => $row['piece_width'] === null && $row['piece_height'] === null ? null : ['width' => $row['piece_width'] === null ? null : (string) $row['piece_width'], 'height' => $row['piece_height'] === null ? null : (string) $row['piece_height'], 'unit' => $row['piece_unit'], 'source' => $row['measurement_source']],
            // Planned fabric of this piece from the confirmed plan; null means unknown (never 0, never a share of the line).
            'fabricMeters' => $row['fabric_length'] === null ? null : (string) $row['fabric_length'],
            'fabricSource' => $row['fabric_source'] ?? 'unknown',
            'productType' => $row['product_type'] ?? 'unknown',
            'planRevision' => $row['plan_revision'] === null ? null : (int) $row['plan_revision'],
            'unitStatus' => $row['unit_status'] === null ? null : (string) $row['unit_status'],
            'replacedBy' => $row['replaced_by'] === null ? null : (string) $row['replaced_by'],
            'reworkCycleAtReport' => (int) $row['rework_cycle_at_report'],
            'trackId' => $row['track_uuid'] === null ? null : (string) $row['track_uuid'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * An administrative recovery link (API 2.32.0): this request recovers an earlier one of the same physical defect, or
     * was recovered by a later one. Quality facts of the incident are counted once. @return array{recovers: ?string, recoveredBy: ?string}
     */
    public function recoveryLinks(string $exceptionUuid): array
    {
        try {
            $statement = $this->pdo->prepare("SELECT exception_uuid, linked_exception_uuid FROM production_exception_links WHERE link_type = 'administrative_recovery' AND (exception_uuid = ? OR linked_exception_uuid = ?)");
            $statement->execute([$exceptionUuid, $exceptionUuid]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return ['recovers' => null, 'recoveredBy' => null];
        }
        $links = ['recovers' => null, 'recoveredBy' => null];
        foreach ($rows as $row) {
            if ($row['exception_uuid'] === $exceptionUuid) {
                $links['recovers'] = (string) $row['linked_exception_uuid'];
            } else {
                $links['recoveredBy'] = (string) $row['exception_uuid'];
            }
        }
        return $links;
    }

    private static function seconds(string $from, string $to): int
    {
        $a = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $from, new \DateTimeZone('UTC'));
        $b = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $to, new \DateTimeZone('UTC'));
        return $a === false || $b === false ? 0 : max(0, $b->getTimestamp() - $a->getTimestamp());
    }
}
