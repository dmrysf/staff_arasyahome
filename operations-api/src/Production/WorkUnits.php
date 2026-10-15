<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Order\UnitLabelReference;
use PDO;

/**
 * Physical pieces (production work units, migration 028).
 *
 * Every order line of quantity N is made of N pieces. A piece's identity is (item_uuid, unit_index) and its
 * unit_uuid is derived from that pair deterministically, so the same piece has the same identity whether it was
 * written by the backfill, on first use, or only computed for a read. A piece with track_uuid NULL travels with the
 * order (the order row's stage and owner are its truth); a piece on a track travels with that track.
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

    public function partialReworkEnabled(): bool
    {
        try {
            $statement = $this->pdo->prepare('SELECT enabled FROM production_feature_flags WHERE flag_key = ?');
            $statement->execute([self::FLAG_PARTIAL_REWORK]);
            return (int) $statement->fetchColumn() === 1;
        } catch (\PDOException) {
            // Migration 028 not applied yet: the feature is off.
            return false;
        }
    }

    /**
     * Turns partial (per-piece) defect reports on or off. Turning it off never touches existing tracks: pieces already
     * in rework finish their way through the workflow; only new partial reports are refused. @return array{enabled: bool, changed: bool}
     */
    public function setPartialRework(bool $enabled, string $reason, string $now): array
    {
        $current = $this->partialReworkEnabled();
        if ($current === $enabled) {
            return ['enabled' => $enabled, 'changed' => false];
        }
        $statement = $this->pdo->prepare('UPDATE production_feature_flags SET enabled = ?, changed_by_employee_uuid = NULL, change_reason = ?, changed_at = ? WHERE flag_key = ?');
        $statement->execute([$enabled ? 1 : 0, mb_substr($reason, 0, 255), $now, self::FLAG_PARTIAL_REWORK]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('The partial_rework flag row is missing; apply migration 028 first.');
        }
        return ['enabled' => $enabled, 'changed' => true];
    }

    /** Pieces materialized so far and pieces flagged for an identity review, for the operator report. @return array<string, int> */
    public function inventory(): array
    {
        $row = $this->pdo->query("SELECT COUNT(*) AS units, COALESCE(SUM(review_required = 1), 0) AS review, (SELECT COUNT(*) FROM production_tracks WHERE status = 'active') AS active_tracks FROM production_work_units WHERE status = 'active'")->fetch(PDO::FETCH_ASSOC);
        return ['activeUnits' => (int) $row['units'], 'reviewRequired' => (int) $row['review'], 'activeTracks' => (int) $row['active_tracks']];
    }

    /**
     * Materializes every piece of the order's current lines (caller holds the order lock). Idempotent. A piece
     * whose line disappeared or whose index exceeds a reduced quantity is retired only while it travels with the
     * order; a piece on a track is never silently retired: it is flagged for explicit review instead.
     */
    public function ensure(string $orderUuid, string $now, string $source = 'on_demand'): void
    {
        $items = $this->pdo->prepare('SELECT item_uuid, quantity FROM operational_order_items WHERE order_uuid = ? ORDER BY line_number');
        $items->execute([$orderUuid]);
        $expected = [];
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            for ($index = 1; $index <= (int) $item['quantity']; $index++) {
                $expected[self::unitUuid((string) $item['item_uuid'], $index)] = [(string) $item['item_uuid'], $index];
            }
        }
        $existing = $this->pdo->prepare('SELECT unit_uuid, status, track_uuid FROM production_work_units WHERE order_uuid = ?');
        $existing->execute([$orderUuid]);
        $rows = [];
        foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(string) $row['unit_uuid']] = $row;
        }
        $insert = $this->pdo->prepare(
            "INSERT INTO production_work_units (unit_uuid, order_uuid, item_uuid, unit_index, status, track_uuid, rework_cycle, identity_source, review_required, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'active', NULL, 1, ?, 0, ?, ?)",
        );
        foreach ($expected as $uuid => [$itemUuid, $index]) {
            if (!isset($rows[$uuid])) {
                $insert->execute([$uuid, $orderUuid, $itemUuid, $index, $source, $now, $now]);
            } elseif ($rows[$uuid]['status'] === 'retired') {
                // The line came back (same source line, same index): the piece is the same identity again.
                $this->pdo->prepare("UPDATE production_work_units SET status = 'active', retired_at = NULL, updated_at = ? WHERE unit_uuid = ?")->execute([$now, $uuid]);
            }
        }
        foreach ($rows as $uuid => $row) {
            if (isset($expected[$uuid]) || $row['status'] !== 'active') {
                continue;
            }
            if ($row['track_uuid'] === null) {
                $this->pdo->prepare("UPDATE production_work_units SET status = 'retired', retired_at = ?, updated_at = ? WHERE unit_uuid = ?")->execute([$now, $now, $uuid]);
            } else {
                $this->pdo->prepare("UPDATE production_work_units SET review_required = 1, review_note = 'The order line changed while this piece was in rework.', updated_at = ? WHERE unit_uuid = ?")->execute([$now, $uuid]);
            }
        }
    }

    /**
     * Active pieces of one order with their line facts, in line and index order. Pieces are computed from the lines
     * for orders that were never materialized, with the same identities.
     *
     * @return list<array{id: string, itemId: string, index: int, count: int, lineNumber: int, name: string, code: ?string, color: ?string, trackId: ?string, reworkCycle: int, reviewRequired: bool}>
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
        $marks = implode(',', array_fill(0, count($orderUuids), '?'));
        $items = $this->pdo->prepare("SELECT order_uuid, item_uuid, line_number, name, product_code, color, width_value, height_value, measurement_unit, quantity FROM operational_order_items WHERE order_uuid IN ({$marks}) ORDER BY order_uuid, line_number");
        $items->execute($orderUuids);
        $stored = [];
        try {
            $units = $this->pdo->prepare("SELECT unit_uuid, track_uuid, rework_cycle, review_required FROM production_work_units WHERE order_uuid IN ({$marks}) AND status = 'active'");
            $units->execute($orderUuids);
            foreach ($units->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $stored[(string) $row['unit_uuid']] = $row;
            }
        } catch (\PDOException) {
            // Before migration 028 every piece travels with its order.
        }
        $result = [];
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $count = (int) $item['quantity'];
            for ($index = 1; $index <= $count; $index++) {
                $uuid = self::unitUuid((string) $item['item_uuid'], $index);
                $row = $stored[$uuid] ?? null;
                $result[(string) $item['order_uuid']][] = [
                    'id' => $uuid,
                    'itemId' => (string) $item['item_uuid'],
                    'index' => $index,
                    'count' => $count,
                    'lineNumber' => (int) $item['line_number'],
                    'name' => (string) $item['name'],
                    'code' => $item['product_code'] === null ? null : (string) $item['product_code'],
                    'color' => $item['color'] === null ? null : (string) $item['color'],
                    'width' => $item['width_value'] === null ? null : (float) $item['width_value'],
                    'height' => $item['height_value'] === null ? null : (float) $item['height_value'],
                    'unit' => $item['measurement_unit'] === null ? null : (string) $item['measurement_unit'],
                    'trackId' => $row === null || $row['track_uuid'] === null ? null : (string) $row['track_uuid'],
                    'reworkCycle' => $row === null ? 1 : (int) $row['rework_cycle'],
                    'reviewRequired' => $row !== null && (int) $row['review_required'] === 1,
                ];
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
     * The piece an active label belongs to, or null. @return array{unitUuid: string, orderUuid: string, globalOrderId: string, trackUuid: ?string, status: string}|null
     */
    public function resolveLabel(UnitLabelReference $label): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT l.unit_uuid, l.order_uuid, l.status, o.global_order_id, u.track_uuid, u.status AS unit_status FROM production_unit_labels l
             INNER JOIN production_work_units u ON u.unit_uuid = l.unit_uuid INNER JOIN operational_orders o ON o.order_uuid = l.order_uuid WHERE l.label_reference = ?',
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
        $statement = $this->pdo->prepare("SELECT * FROM production_work_units WHERE order_uuid = ? AND status = 'active' AND track_uuid <=> ? AND unit_uuid IN ({$marks}) FOR UPDATE");
        $statement->execute([$orderUuid, $trackUuid, ...$unitUuids]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== count($unitUuids)) {
            throw new ApiException(422, 'UNITS_INVALID', 'Every selected piece must be an active piece that is here, on this production track.');
        }
        foreach ($rows as $row) {
            if ((int) $row['review_required'] === 1) {
                throw new ApiException(409, 'UNIT_REVIEW_REQUIRED', 'A selected piece needs a manager review of its identity first.');
            }
        }
        return $rows;
    }

    /** Active pieces on one carrier (main track when null). @return list<array<string, mixed>> */
    public function carrierUnits(string $orderUuid, ?string $trackUuid): array
    {
        $statement = $this->pdo->prepare("SELECT * FROM production_work_units WHERE order_uuid = ? AND status = 'active' AND track_uuid <=> ? ORDER BY item_uuid, unit_index");
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
        } catch (\PDOException) {
            return 0;
        }
    }
}
