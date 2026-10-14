<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Uuid;
use PDO;
use PDOException;

/**
 * Fabric records per physical piece and production cycle (migration 029). Append-only; quantities are exact decimal
 * metres (DECIMAL(12,3)); unknown planned fabric stays NULL, never zero.
 *
 *   planned / replacement_planned   what the confirmed plan requires (written by the system)
 *   actual / replacement_actual     what the cutter really used, one per piece, kind and cycle
 *   waste                           defective or unusable material of a piece in a cycle
 *   remnant                         usable material left over
 *   correction                      a signed-off correction of an earlier record (the earlier record stays)
 *
 * stock_effect is always 'none': there is no authoritative stock ledger, so no warehouse quantity is changed, and a
 * customer's commercial order total is never touched. The dedupe key makes a record exist once per piece, kind and cycle,
 * whatever the idempotency key of a retry is.
 */
final readonly class MaterialLedger
{
    public const RECORDED_KINDS = ['actual', 'replacement_actual', 'waste', 'remnant', 'correction'];

    public function __construct(private PDO $pdo)
    {
    }

    /** A system record (planned fabric). Written once per dedupe key; later calls change nothing. */
    public function recordSystem(string $orderUuid, string $itemUuid, ?string $unitUuid, ?string $exceptionUuid, int $cycle, string $kind, ?string $quantity, string $dedupeKey, string $note, string $actorUuid, string $requestId, string $key, string $now): void
    {
        $exists = $this->pdo->prepare('SELECT 1 FROM production_material_records WHERE dedupe_key = ?');
        $exists->execute([$dedupeKey]);
        if ($exists->fetchColumn() !== false) {
            return;
        }
        $this->insert($orderUuid, $itemUuid, $unitUuid, $exceptionUuid, $cycle, $kind, $quantity, $dedupeKey, null, $note, $actorUuid, $requestId, 'm-' . substr(hash('sha256', $dedupeKey . '|' . $key), 0, 60), $now);
    }

    /**
     * A record made by an employee for one piece (caller locked the order and checked authority).
     * @param array<string, mixed> $unit locked unit row @param array<string, mixed> $input @return array<string, mixed> the stored record
     */
    public function record(array $unit, array $input, string $actorUuid, string $requestId, string $key, string $now): array
    {
        $kind = $input['kind'] ?? null;
        if (!in_array($kind, self::RECORDED_KINDS, true)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'kind trebuie să fie actual, replacement_actual, waste, remnant sau correction.');
        }
        // A replacement consumes new fabric once. Changing the record kind must not consume the same cut twice.
        if (in_array($kind, ['actual', 'replacement_actual'], true)) {
            $replacement = $this->pdo->prepare('SELECT 1 FROM production_unit_replacements WHERE replacement_unit_uuid = ?');
            $replacement->execute([$unit['unit_uuid']]);
            $expectedKind = $replacement->fetchColumn() === false ? 'actual' : 'replacement_actual';
            if ($kind !== $expectedKind) {
                throw new ApiException(422, 'MATERIAL_KIND_MISMATCH', 'Folosește tipul de consum al bucății: ' . $expectedKind . '.');
            }
        }
        $meters = $input['meters'] ?? null;
        if (!is_string($meters) || preg_match('/^\d{1,9}(?:\.\d{1,3})?$/D', $meters) !== 1) {
            throw new ApiException(400, 'INVALID_REQUEST', 'meters trebuie să fie un șir zecimal cu maximum 3 zecimale.');
        }
        $quantity = ManufacturingPlans::normalize($meters);
        $note = is_string($input['note'] ?? null) ? mb_substr(trim($input['note']), 0, 500) : null;
        $cycle = (int) $unit['rework_cycle'];
        $corrects = null;
        if ($kind === 'correction') {
            $corrects = $input['correctsRecordId'] ?? null;
            if (!WorkUnits::isUuid($corrects) || $note === null || mb_strlen($note) < 3) {
                throw new ApiException(400, 'INVALID_REQUEST', 'Corecția trebuie să identifice înregistrarea corectată și să o explice.');
            }
            $target = $this->pdo->prepare('SELECT unit_uuid, kind FROM production_material_records WHERE record_uuid = ?');
            $target->execute([$corrects]);
            $row = $target->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $row['unit_uuid'] !== $unit['unit_uuid'] || $row['kind'] === 'correction') {
                throw new ApiException(422, 'MATERIAL_RECORD_INVALID', 'Înregistrarea corectată nu aparține acestei bucăți.');
            }
            $dedupe = 'correction|' . $corrects . '|' . hash('sha256', $actorUuid . '|' . $key);
        } else {
            $dedupe = $kind . '|' . $unit['unit_uuid'] . '|' . $cycle;
        }
        $exists = $this->pdo->prepare('SELECT record_uuid FROM production_material_records WHERE dedupe_key = ?');
        $exists->execute([$dedupe]);
        if ($exists->fetchColumn() !== false) {
            throw new ApiException(409, 'MATERIAL_ALREADY_RECORDED', 'Acest consum este deja înregistrat pentru bucată în acest ciclu. Folosește o corecție.');
        }
        $uuid = $this->insert((string) $unit['order_uuid'], (string) $unit['item_uuid'], (string) $unit['unit_uuid'], null, $cycle, (string) $kind, $quantity, $dedupe, $corrects, $note, $actorUuid, $requestId, $key, $now);
        return ['id' => $uuid, 'unitId' => (string) $unit['unit_uuid'], 'kind' => $kind, 'meters' => $quantity, 'reworkCycle' => $cycle, 'stockEffect' => 'none', 'correctsRecordId' => $corrects];
    }

    /** Records of the given orders' pieces, oldest first. @param list<string> $unitUuids @return list<array<string, mixed>> */
    public function forUnits(array $unitUuids): array
    {
        if ($unitUuids === []) {
            return [];
        }
        try {
            $statement = $this->pdo->prepare('SELECT m.*, e.display_name FROM production_material_records m INNER JOIN employees e ON e.employee_uuid = m.recorded_by_employee_uuid
                 WHERE m.unit_uuid IN (' . implode(',', array_fill(0, count($unitUuids), '?')) . ') ORDER BY m.recorded_at, m.record_uuid');
            $statement->execute(array_values($unitUuids));
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1146) throw $error;
            return [];
        }
        return array_map(static fn (array $row): array => [
            'id' => (string) $row['record_uuid'], 'unitId' => $row['unit_uuid'], 'kind' => (string) $row['kind'], 'reworkCycle' => (int) $row['rework_cycle'],
            'meters' => $row['quantity'] === null ? null : ManufacturingPlans::normalize((string) $row['quantity']), 'stockEffect' => (string) $row['stock_effect'],
            'correctsRecordId' => $row['corrects_record_uuid'], 'note' => $row['note'], 'recordedBy' => (string) $row['display_name'],
            'recordedAt' => substr(str_replace(' ', 'T', (string) $row['recorded_at']), 0, 23) . 'Z',
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function insert(string $orderUuid, string $itemUuid, ?string $unitUuid, ?string $exceptionUuid, int $cycle, string $kind, ?string $quantity, string $dedupe, ?string $corrects, ?string $note, string $actorUuid, string $requestId, string $key, string $now): string
    {
        $uuid = Uuid::v4();
        $this->pdo->prepare(
            "INSERT INTO production_material_records (record_uuid, order_uuid, item_uuid, unit_uuid, exception_uuid, rework_cycle, kind, quantity, unit, stock_effect, dedupe_key, corrects_record_uuid, note,
                recorded_by_employee_uuid, request_id, idempotency_key, recorded_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'm', 'none', ?, ?, ?, ?, ?, ?, ?)",
        )->execute([$uuid, $orderUuid, $itemUuid, $unitUuid, $exceptionUuid, max(1, $cycle), $kind, $quantity, mb_substr($dedupe, 0, 190), $corrects, $note, $actorUuid, mb_substr($requestId, 0, 100), mb_substr($key, 0, 100), $now]);
        return $uuid;
    }
}
