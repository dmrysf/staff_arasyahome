<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Order\UnitLabelReference;
use PDO;

/**
 * Physical pieces (production work units, migrations 028 and 029).
 *
 * The pieces of a commercial line come from its manufacturing plan (ManufacturingPlans), never from the commercial
 * quantity: a line of quantity 1 may be 2 physical pieces. A line whose piece count is unknown (or must be re-confirmed
 * after a source change) has no individual pieces at all until an authorized person confirms its plan; it keeps the
 * order-level behaviour. Each piece fills a deliverable slot 1..pieceCount. The original piece of a slot has the
 * identity (item_uuid, unit_index = slot) and its unit_uuid is derived from that pair deterministically, so it is the
 * same before and after it is written. A full replacement is a new piece with its own identity and label that takes
 * the slot of the retired original (production_unit_replacements), so a slot never has two deliverable pieces.
 * A piece with track_uuid NULL travels with the order (the order row's stage and owner are its truth); a piece on a
 * track travels with that track.
 *
 * Writes (ensure, labels) run inside the caller's transaction after the caller locked the order row, the same
 * lock every production mutation takes first, so two requests can never materialize or move a piece concurrently.
 */
final readonly class WorkUnits
{
    public const FLAG_PARTIAL_REWORK = 'partial_rework';
    private const NAMESPACE = 'arasya-production-work-unit';

    public function __construct(private PDO $pdo)
    {
    }

    /** Deterministic name-based UUID (RFC 4122 version 5 layout) of one piece. */
    public static function unitUuid(string $itemUuid, int $index): string
    {
        $hash = sha1(self::NAMESPACE . '|' . $itemUuid . '|' . $index);
        $bytes = hex2bin(substr($hash, 0, 32));
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x50);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    public static function isUuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) === 1;
    }

    /** Whether the partial_rework flag is enabled globally (a pilot cohort may enable it for single orders). */
    public function partialReworkEnabled(): bool
    {
        return (new FeatureActivation($this->pdo))->enabled(self::FLAG_PARTIAL_REWORK);
    }

    /**
     * Turns partial (per-piece) defect reports on or off globally, audited. Turning it off never touches existing tracks:
     * pieces already in rework finish their way through the workflow; only new partial reports are refused.
     * @return array{enabled: bool, changed: bool}
     */
    public function setPartialRework(bool $enabled, string $reason, string $now, string $operator = 'operator'): array
    {
        return (new FeatureActivation($this->pdo))->setEnabled(self::FLAG_PARTIAL_REWORK, $enabled, $operator, $reason, $now);
    }

    /** Pieces materialized so far, pieces flagged for an identity review and tracks, for the operator report. @return array<string, int> */
    public function inventory(): array
    {
        $row = $this->pdo->query("SELECT COUNT(*) AS units, COALESCE(SUM(review_required = 1), 0) AS review,
                (SELECT COUNT(*) FROM production_tracks WHERE status = 'active') AS active_tracks,
                (SELECT COUNT(*) FROM production_tracks WHERE status = 'active' AND origin_exception_uuid IS NULL) AS route_tracks,
                (SELECT COUNT(*) FROM production_unit_replacements) AS replacements
             FROM production_work_units WHERE status = 'active'")->fetch(PDO::FETCH_ASSOC);
        return ['activeUnits' => (int) $row['units'], 'reviewRequired' => (int) $row['review'], 'activeTracks' => (int) $row['active_tracks'],
            'activeRouteTracks' => (int) $row['route_tracks'], 'replacements' => (int) $row['replacements']];
    }

    /**
     * Materializes the pieces of every line of the order whose plan is known (caller holds the order lock). Idempotent.
     * A slot without an active piece gets its original piece (written, or re-activated when the plan grows back). A
     * piece whose slot no longer exists (plan reduced, line gone) is retired only while it travels with the order; a
     * piece on a track is never silently retired: it is flagged for explicit review instead. Lines whose plan is unknown
     * are left untouched: nothing is invented and nothing is retired.
     */
    public function ensure(string $orderUuid, string $now, string $source = 'on_demand'): void
    {
        $counts = (new ManufacturingPlans($this->pdo))->pieceCounts($orderUuid);
        $stage = $this->pdo->prepare('SELECT production_stage_id FROM operational_orders WHERE order_uuid = ?');
        $stage->execute([$orderUuid]);
        $orderStage = (string) $stage->fetchColumn();
        $bySlot = [];
        $rows = [];
        foreach ($this->unitRows($orderUuid) as $row) {
            $rows[(string) $row['unit_uuid']] = $row;
            if ($row['status'] === 'active') {
                $bySlot[(string) $row['item_uuid']][(int) $row['slot']] = $row;
            }
        }
        $insert = $this->pdo->prepare(
            "INSERT INTO production_work_units (unit_uuid, order_uuid, item_uuid, unit_index, status, track_uuid, rework_cycle, identity_source, review_required, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'active', NULL, 1, ?, 0, ?, ?)",
        );
        foreach ($counts as $itemUuid => $count) {
            if ($count === null) {
                continue;
            }
            for ($slot = 1; $slot <= $count; $slot++) {
                if (isset($bySlot[$itemUuid][$slot])) {
                    continue;
                }
                $uuid = self::unitUuid($itemUuid, $slot);
                $original = $rows[$uuid] ?? null;
                if ($original === null) {
                    $insert->execute([$uuid, $orderUuid, $itemUuid, $slot, $source, $now, $now]);
                    $this->startCycle($uuid, 1, $orderStage, $now);
                } elseif ($original['replaced_by'] === null) {
                    // The slot came back (same source line, same index): the piece is the same identity again.
                    $this->pdo->prepare("UPDATE production_work_units SET status = 'active', retired_at = NULL, updated_at = ? WHERE unit_uuid = ?")->execute([$now, $uuid]);
                }
                // A replaced original never comes back: its replacement holds the slot.
            }
        }
        foreach ($rows as $uuid => $row) {
            $count = array_key_exists((string) $row['item_uuid'], $counts) ? $counts[(string) $row['item_uuid']] : 0;
            if ($row['status'] !== 'active' || $count === null || (int) $row['slot'] <= $count) {
                continue;
            }
            if (!array_key_exists((string) $row['item_uuid'], $counts)) {
                $this->pdo->prepare("UPDATE production_work_units SET review_required = 1, review_note = 'Source line removed; physical identity requires authorized reconciliation.', updated_at = ? WHERE unit_uuid = ?")->execute([$now, $uuid]);
                continue;
            }
            if ($row['track_uuid'] === null) {
                $this->pdo->prepare("UPDATE production_work_units SET status = 'retired', retired_at = ?, updated_at = ? WHERE unit_uuid = ?")->execute([$now, $now, $uuid]);
            } else {
                $this->pdo->prepare("UPDATE production_work_units SET review_required = 1, review_note = 'The order line or its plan changed while this piece was in rework.', updated_at = ? WHERE unit_uuid = ?")->execute([$now, $uuid]);
            }
        }
    }

    /** Where the per-piece stage ledger of a cycle starts (idempotent). */
    public function startCycle(string $unitUuid, int $cycle, string $stageId, string $now): void
    {
        $this->pdo->prepare('INSERT INTO production_unit_cycles (unit_uuid, rework_cycle, started_stage_id, started_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE started_at = started_at')
            ->execute([$unitUuid, $cycle, $stageId, $now]);
    }

    /**
     * Every stored piece of the order with its deliverable slot and lineage. @return list<array<string, mixed>>
     */
    private function unitRows(string $orderUuid): array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.unit_uuid, u.item_uuid, u.unit_index, u.status, u.track_uuid, u.rework_cycle, u.review_required, COALESCE(r.slot_index, u.unit_index) AS slot,
                    r.original_unit_uuid AS replaces, n.replacement_unit_uuid AS replaced_by
             FROM production_work_units u
             LEFT JOIN production_unit_replacements r ON r.replacement_unit_uuid = u.unit_uuid
             LEFT JOIN production_unit_replacements n ON n.original_unit_uuid = u.unit_uuid
             WHERE u.order_uuid = ?',
        );
        $statement->execute([$orderUuid]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Active pieces of one order with their line and plan facts, in line and slot order. Only lines with a known plan
     * have pieces; pieces not yet written are computed with the same identities.
     *
     * @return list<array<string, mixed>>
     */
    public function pieces(string $orderUuid): array
    {
        return $this->piecesFor([$orderUuid])[$orderUuid] ?? [];
    }

    /** @param list<string> $orderUuids @return array<string, list<array<string, mixed>>> */
    public function piecesFor(array $orderUuids): array
    {
        $orderUuids = array_values(array_unique($orderUuids));
        if ($orderUuids === []) {
            return [];
        }
        $stored = [];
        try {
            $marks = implode(',', array_fill(0, count($orderUuids), '?'));
            $units = $this->pdo->prepare(
                "SELECT u.unit_uuid, u.item_uuid, u.unit_index, u.track_uuid, u.rework_cycle, u.review_required, COALESCE(r.slot_index, u.unit_index) AS slot, r.original_unit_uuid AS replaces, v.detected_stage_id, v.confirmed_at AS defect_confirmed_at
                 FROM production_work_units u LEFT JOIN production_unit_replacements r ON r.replacement_unit_uuid = u.unit_uuid
                 LEFT JOIN production_unit_verifications v ON v.unit_uuid = u.unit_uuid AND v.rework_cycle = u.rework_cycle
                 WHERE u.order_uuid IN ({$marks}) AND u.status = 'active'",
            );
            $units->execute($orderUuids);
            foreach ($units->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $stored[(string) $row['item_uuid']][(int) $row['slot']] = $row;
            }
        } catch (\PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1146) throw $error;
            // Before migration 029 no piece has a plan.
        }
        $result = [];
        foreach ((new ManufacturingPlans($this->pdo))->linesFor($orderUuids) as $orderUuid => $lines) {
            foreach ($lines as $line) {
                if (!$line['known']) {
                    continue;
                }
                $count = (int) $line['plan']['pieceCount'];
                $planPieces = [];
                foreach ($line['plan']['pieces'] as $planPiece) {
                    $planPieces[$planPiece['index']] = $planPiece;
                }
                for ($slot = 1; $slot <= $count; $slot++) {
                    $row = $stored[$line['itemId']][$slot] ?? null;
                    $plan = $planPieces[$slot] ?? null;
                    $result[$orderUuid][] = [
                        'id' => $row === null ? self::unitUuid($line['itemId'], $slot) : (string) $row['unit_uuid'],
                        'itemId' => $line['itemId'],
                        'index' => $slot,
                        'count' => $count,
                        'lineNumber' => $line['lineNumber'],
                        'name' => $line['name'],
                        'code' => $line['code'],
                        'color' => $line['color'],
                        'productType' => $line['plan']['productType'],
                        'width' => $plan['width'] ?? null,
                        'height' => $plan['height'] ?? null,
                        'unit' => $plan['unit'] ?? null,
                        'fabricMeters' => $plan['fabricMeters'] ?? null,
                        'trackId' => $row === null || $row['track_uuid'] === null ? null : (string) $row['track_uuid'],
                        'reworkCycle' => $row === null ? 1 : (int) $row['rework_cycle'],
                        'reviewRequired' => $row !== null && (int) $row['review_required'] === 1,
                        'replacementOf' => $row === null || $row['replaces'] === null ? null : (string) $row['replaces'],
                        'materialized' => $row !== null,
                        'defectResolution' => $row === null || $row['detected_stage_id'] === null ? null : ['detectedStageId' => (string) $row['detected_stage_id'], 'confirmedAt' => $row['defect_confirmed_at']],
                    ];
                }
            }
        }
        return $result;
    }

    /**
     * Issues one active printable label per piece (keeps an existing active label). Caller holds the order lock.
     * @param list<string> $unitUuids
     */
    public function issueLabels(string $orderUuid, array $unitUuids, string $issuerUuid, string $now): void
    {
        $active = $this->pdo->prepare("SELECT 1 FROM production_unit_labels WHERE unit_uuid = ? AND status = 'active' LIMIT 1");
        $insert = $this->pdo->prepare("INSERT INTO production_unit_labels (label_reference, unit_uuid, order_uuid, status, issued_by_employee_uuid, issued_at) VALUES (?, ?, ?, 'active', ?, ?)");
        foreach ($unitUuids as $unitUuid) {
            $active->execute([$unitUuid]);
            if ($active->fetchColumn() === false) {
                $insert->execute([UnitLabelReference::generate()->value, $unitUuid, $orderUuid, $issuerUuid, $now]);
            }
        }
    }

    /**
     * The piece a label belongs to, or null. @return array{unitUuid: string, orderUuid: string, globalOrderId: string, trackUuid: ?string, status: string, replacedBy: ?string}|null
     */
    public function resolveLabel(UnitLabelReference $label): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT l.unit_uuid, l.order_uuid, l.status, o.global_order_id, u.track_uuid, u.status AS unit_status, n.replacement_unit_uuid AS replaced_by FROM production_unit_labels l
             INNER JOIN production_work_units u ON u.unit_uuid = l.unit_uuid INNER JOIN operational_orders o ON o.order_uuid = l.order_uuid
             LEFT JOIN production_unit_replacements n ON n.original_unit_uuid = l.unit_uuid WHERE l.label_reference = ?',
        );
        $statement->execute([$label->value]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return [
            'unitUuid' => (string) $row['unit_uuid'],
            'orderUuid' => (string) $row['order_uuid'],
            'globalOrderId' => (string) $row['global_order_id'],
            'trackUuid' => $row['track_uuid'] === null ? null : (string) $row['track_uuid'],
            'status' => $row['status'] === 'active' && $row['unit_status'] === 'active' ? 'active' : 'revoked',
            // A label of a replaced piece stays readable for audit and never authorizes work.
            'replacedBy' => $row['replaced_by'] === null ? null : (string) $row['replaced_by'],
        ];
    }

    /** Active labels of the given pieces, with the printable payload. @param list<string> $unitUuids @return array<string, string> unit uuid => payload */
    public function activeLabels(array $unitUuids): array
    {
        if ($unitUuids === []) {
            return [];
        }
        $statement = $this->pdo->prepare("SELECT unit_uuid, label_reference FROM production_unit_labels WHERE status = 'active' AND unit_uuid IN (" . implode(',', array_fill(0, count($unitUuids), '?')) . ')');
        $statement->execute(array_values($unitUuids));
        $labels = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $labels[(string) $row['unit_uuid']] = UnitLabelReference::fromStored((string) $row['label_reference'])->payload();
        }
        return $labels;
    }

    /**
     * Validates a client's piece selection against the materialized active pieces of one carrier (the order's main
     * track when $trackUuid is null). Unknown, retired, duplicated or foreign pieces fail closed.
     *
     * @param list<string> $unitUuids
     * @return list<array<string, mixed>> the selected pieces (rows of production_work_units)
     */
    public function requireCarrierUnits(string $orderUuid, ?string $trackUuid, array $unitUuids): array
    {
        if ($unitUuids === [] || count(array_unique($unitUuids)) !== count($unitUuids)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'units must list distinct pieces.');
        }
        $marks = implode(',', array_fill(0, count($unitUuids), '?'));
        $statement = $this->pdo->prepare("SELECT u.*, COALESCE(r.slot_index, u.unit_index) AS slot FROM production_work_units u LEFT JOIN production_unit_replacements r ON r.replacement_unit_uuid = u.unit_uuid
             WHERE u.order_uuid = ? AND u.status = 'active' AND u.track_uuid <=> ? AND u.unit_uuid IN ({$marks}) FOR UPDATE");
        $statement->execute([$orderUuid, $trackUuid, ...$unitUuids]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== count($unitUuids)) {
            throw new ApiException(422, 'UNITS_INVALID', 'Every selected piece must be an active piece that is here, on this production track.');
        }
        $counts = (new ManufacturingPlans($this->pdo))->pieceCounts($orderUuid);
        foreach ($rows as $row) {
            if ((int) $row['review_required'] === 1) {
                throw new ApiException(409, 'UNIT_REVIEW_REQUIRED', 'A selected piece needs a manager review of its identity first.');
            }
            if (($counts[(string) $row['item_uuid']] ?? null) === null) {
                throw new ApiException(409, 'PLAN_CONFIRMATION_REQUIRED', 'Planul de producție al produsului trebuie confirmat (numărul real de bucăți) înainte de a lucra pe bucăți.', ['itemIds' => [(string) $row['item_uuid']]]);
            }
        }
        return $rows;
    }

    /** Active pieces on one carrier (main track when null). @return list<array<string, mixed>> */
    public function carrierUnits(string $orderUuid, ?string $trackUuid): array
    {
        $statement = $this->pdo->prepare("SELECT u.*, COALESCE(r.slot_index, u.unit_index) AS slot FROM production_work_units u LEFT JOIN production_unit_replacements r ON r.replacement_unit_uuid = u.unit_uuid
             WHERE u.order_uuid = ? AND u.status = 'active' AND u.track_uuid <=> ? ORDER BY u.item_uuid, slot");
        $statement->execute([$orderUuid, $trackUuid]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Whether the order has any active track (pieces away from the order). */
    public function activeTrackCount(string $orderUuid): int
    {
        try {
            $statement = $this->pdo->prepare("SELECT COUNT(*) FROM production_tracks WHERE order_uuid = ? AND status = 'active'");
            $statement->execute([$orderUuid]);
            return (int) $statement->fetchColumn();
        } catch (\PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1146) throw $error;
            return 0;
        }
    }
}
