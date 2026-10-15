<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Uuid;
use PDO;

/**
 * Persistence of rework tracks (migration 028), always inside the caller's transaction and after the caller locked
 * the order row. A track is a separate production carrier for pieces that left the order because only they were
 * reported defective. It has its own stage, owner, version and rework cycle. Every change is guarded by the expected
 * track version and written together with an append-only production_track_events row.
 *
 * Rejoin rules (merge): a track whose pieces were reworked rejoins the order only at packing, once both the order and
 * the track arrived there unowned and unblocked; packing of the order is refused while any track is active, so an
 * order can never be packed, delivered or completed with a piece still in rework. A track that never went through a
 * rework (a dismissed report, a cancelled one, a product handed back to tailoring) rejoins immediately when it is
 * exactly where the order is (same stage, same owner, both unblocked); otherwise it continues on its own.
 */
final readonly class ProductionTracks
{
    public const GATE_STAGE = 'packing';

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null the locked track row */
    public function lock(string $trackUuid): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM production_tracks WHERE track_uuid = ? FOR UPDATE');
        $statement->execute([$trackUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function orderOf(string $trackUuid): ?string
    {
        $statement = $this->pdo->prepare('SELECT order_uuid FROM production_tracks WHERE track_uuid = ?');
        $statement->execute([$trackUuid]);
        $value = $statement->fetchColumn();
        return is_string($value) ? $value : null;
    }

    /**
     * Moves the given pieces off their carrier onto a new active track at the carrier's stage, with the carrier's owner
     * and the given open exception. Pieces must already be locked and validated by the caller.
     *
     * @param list<string> $unitUuids
     */
    public function split(string $orderUuid, ?string $parentTrackUuid, array $unitUuids, string $stageId, ?string $ownerUuid, string $exceptionUuid, int $reworkCycle, string $actorUuid, string $key, string $requestId, string $now): string
    {
        $trackUuid = Uuid::v4();
        $this->pdo->prepare(
            "INSERT INTO production_tracks (track_uuid, order_uuid, parent_track_uuid, origin_exception_uuid, status, stage_id, owner_employee_uuid, claimed_at, changed_at, version,
                rework_cycle, open_exception_uuid, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, 1, ?, ?, ?, ?)",
        )->execute([$trackUuid, $orderUuid, $parentTrackUuid, $exceptionUuid, $stageId, $ownerUuid, $ownerUuid === null ? null : $now, $now, $reworkCycle, $exceptionUuid, $now, $now]);
        $marks = implode(',', array_fill(0, count($unitUuids), '?'));
        $move = $this->pdo->prepare("UPDATE production_work_units SET track_uuid = ?, updated_at = ? WHERE order_uuid = ? AND status = 'active' AND track_uuid <=> ? AND unit_uuid IN ({$marks})");
        $move->execute([$trackUuid, $now, $orderUuid, $parentTrackUuid, ...$unitUuids]);
        if ($move->rowCount() !== count($unitUuids)) {
            throw new ApiException(409, 'ORDER_CHANGED', 'The pieces changed. Reload the order before continuing.');
        }
        // The creation event is version 0 -> 1 of the new track.
        $this->event($trackUuid, $orderUuid, 'created', $actorUuid, $stageId, null, null, $ownerUuid, 0, $reworkCycle, $exceptionUuid,
            ['units' => $unitUuids, 'parentTrack' => $parentTrackUuid, 'reason' => 'partial_defect_report'], $requestId, $key . '.split', $now);
        return $trackUuid;
    }

    /**
     * Moves pieces of a carrier that follow another product route onto a new route track, unowned, at their own next
     * stage (no exception: this is ordinary production). A piece split off a reworked track keeps that track's rework
     * cycle and its pending defect verification. Pieces must already be locked by the caller.
     *
     * @param array<string, mixed>|null $parentTrack the locked carrier track, or null for the order @param list<string> $unitUuids
     */
    public function splitRoute(string $orderUuid, ?array $parentTrack, array $unitUuids, string $stageId, int $reworkCycle, string $actorUuid, string $key, string $requestId, string $now): string
    {
        $trackUuid = Uuid::v4();
        $parentUuid = $parentTrack === null ? null : (string) $parentTrack['track_uuid'];
        $verify = $parentTrack !== null && $parentTrack['verify_stage_id'] !== null;
        $this->pdo->prepare(
            "INSERT INTO production_tracks (track_uuid, order_uuid, parent_track_uuid, origin_exception_uuid, status, stage_id, owner_employee_uuid, claimed_at, changed_at, version,
                rework_cycle, open_exception_uuid, verify_stage_id, verify_exception_uuid, resolution_confirmed_at, resolution_confirmed_by_employee_uuid, created_at, updated_at)
             VALUES (?, ?, ?, NULL, 'active', ?, NULL, NULL, ?, 1, ?, NULL, ?, ?, ?, ?, ?, ?)",
        )->execute([$trackUuid, $orderUuid, $parentUuid, $stageId, $now, $reworkCycle,
            $verify ? $parentTrack['verify_stage_id'] : null, $verify ? $parentTrack['verify_exception_uuid'] : null,
            $verify ? $parentTrack['resolution_confirmed_at'] : null, $verify ? $parentTrack['resolution_confirmed_by_employee_uuid'] : null, $now, $now]);
        $marks = implode(',', array_fill(0, count($unitUuids), '?'));
        $move = $this->pdo->prepare("UPDATE production_work_units SET track_uuid = ?, updated_at = ? WHERE order_uuid = ? AND status = 'active' AND track_uuid <=> ? AND unit_uuid IN ({$marks})");
        $move->execute([$trackUuid, $now, $orderUuid, $parentUuid, ...$unitUuids]);
        if ($move->rowCount() !== count($unitUuids)) {
            throw new ApiException(409, 'ORDER_CHANGED', 'The pieces changed. Reload the order before continuing.');
        }
        $this->event($trackUuid, $orderUuid, 'created', $actorUuid, $stageId, null, null, null, 0, $reworkCycle, null,
            ['units' => $unitUuids, 'parentTrack' => $parentUuid, 'reason' => 'route_split'], $requestId, $key . '.split', $now);
        return $trackUuid;
    }

    /**
     * Rejoins with the order every route track (no defect history, nothing open) that waits unowned at exactly the stage
     * where the order waits unowned and unblocked: from there the pieces travel together again. Reworked tracks keep
     * rejoining only at packing (mergeAtGate). @return int merged tracks
     */
    public function mergeAligned(string $orderUuid, string $actorUuid, string $key, string $requestId, string $now): int
    {
        $order = $this->pdo->prepare('SELECT production_stage_id, production_owner_employee_uuid, open_exception_uuid, production_completed_at FROM operational_orders WHERE order_uuid = ?');
        $order->execute([$orderUuid]);
        $row = $order->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['production_owner_employee_uuid'] !== null || $row['open_exception_uuid'] !== null || $row['production_completed_at'] !== null) {
            return 0;
        }
        try {
            $tracks = $this->pdo->prepare("SELECT track_uuid FROM production_tracks WHERE order_uuid = ? AND status = 'active' AND stage_id = ? AND owner_employee_uuid IS NULL
                 AND open_exception_uuid IS NULL AND origin_exception_uuid IS NULL AND verify_stage_id IS NULL ORDER BY track_number FOR UPDATE");
            $tracks->execute([$orderUuid, $row['production_stage_id']]);
        } catch (\PDOException) {
            return 0;
        }
        $merged = 0;
        foreach ($tracks->fetchAll(PDO::FETCH_COLUMN) as $trackUuid) {
            $track = $this->lock((string) $trackUuid);
            if ($track !== null) {
                $this->merge($track, 'route_aligned', $actorUuid, $key . '.aligned.' . $merged, $requestId, $now);
                $merged++;
            }
        }
        return $merged;
    }

    /**
     * Applies columns to an active track and increments its version, guarded by the expected version and an extra
     * condition; anything changed concurrently is a 409, never an overwrite.
     *
     * @param array<string, string|int|null> $columns
     */
    public function update(string $trackUuid, int $expectedVersion, array $columns, string $now, string $guard = '1 = 1'): void
    {
        $set = [];
        $values = [];
        foreach ($columns as $column => $value) {
            $set[] = "{$column} = ?";
            $values[] = $value;
        }
        $statement = $this->pdo->prepare('UPDATE production_tracks SET ' . implode(', ', $set) . ($set === [] ? '' : ', ') . "version = version + 1, updated_at = ?
             WHERE track_uuid = ? AND version = ? AND status = 'active' AND " . $guard);
        $statement->execute([...$values, $now, $trackUuid, $expectedVersion]);
        if ($statement->rowCount() !== 1) {
            throw new ApiException(409, 'TRACK_CHANGED', 'The piece in rework changed. Reload it before continuing.');
        }
    }

    /** @param array<string, mixed>|null $metadata */
    public function event(string $trackUuid, string $orderUuid, string $action, string $actorUuid, string $fromStage, ?string $toStage, ?string $previousOwner, ?string $newOwner, int $versionBefore, int $cycle, ?string $exceptionUuid, ?array $metadata, string $requestId, string $key, string $now): void
    {
        $this->pdo->prepare(
            'INSERT INTO production_track_events (event_uuid, track_uuid, order_uuid, action, employee_uuid, from_stage_id, to_stage_id, previous_owner_employee_uuid, new_owner_employee_uuid,
                version_before, version_after, rework_cycle, exception_uuid, metadata_json, request_id, idempotency_key, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            Uuid::v4(), $trackUuid, $orderUuid, $action, $actorUuid, $fromStage, $toStage, $previousOwner, $newOwner, $versionBefore, $versionBefore + 1, $cycle, $exceptionUuid,
            $metadata === null ? null : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            mb_substr($requestId, 0, 100), self::eventKey($action, $key), $now,
        ]);
    }

    /** Event keys are unique per employee: derived from the request key and the action, at most 100 characters. */
    public static function eventKey(string $action, string $key): string
    {
        return 't-' . substr(hash('sha256', $action . '|' . $key), 0, 60);
    }

    /**
     * Rejoins a never-reworked track with the order when it is exactly where the order is: same stage, same owner,
     * both unblocked. Returns whether it merged. @param array<string, mixed> $order locked order row
     */
    public function mergeIfAligned(string $trackUuid, array $order, string $reason, string $actorUuid, string $key, string $requestId, string $now): bool
    {
        $track = $this->lock($trackUuid);
        if ($track === null || $track['status'] !== 'active' || $track['open_exception_uuid'] !== null || $track['verify_stage_id'] !== null) {
            return false;
        }
        if ($order['open_exception_uuid'] !== null || $order['production_completed_at'] !== null
            || $order['production_stage_id'] !== $track['stage_id'] || $order['production_owner_employee_uuid'] !== $track['owner_employee_uuid']) {
            return false;
        }
        $this->merge($track, $reason, $actorUuid, $key, $requestId, $now);
        return true;
    }

    /**
     * Rejoins every track that waits at packing, unowned and unblocked, when the order itself is at packing,
     * unowned and unblocked. Called after the order or a track arrived at packing. @return int merged tracks
     */
    public function mergeAtGate(string $orderUuid, string $actorUuid, string $key, string $requestId, string $now): int
    {
        $order = $this->pdo->prepare('SELECT production_stage_id, production_owner_employee_uuid, open_exception_uuid, production_completed_at FROM operational_orders WHERE order_uuid = ?');
        $order->execute([$orderUuid]);
        $row = $order->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['production_stage_id'] !== self::GATE_STAGE || $row['production_owner_employee_uuid'] !== null || $row['open_exception_uuid'] !== null || $row['production_completed_at'] !== null) {
            return 0;
        }
        $tracks = $this->pdo->prepare("SELECT track_uuid FROM production_tracks WHERE order_uuid = ? AND status = 'active' AND stage_id = ? AND owner_employee_uuid IS NULL AND open_exception_uuid IS NULL FOR UPDATE");
        $tracks->execute([$orderUuid, self::GATE_STAGE]);
        $merged = 0;
        foreach ($tracks->fetchAll(PDO::FETCH_COLUMN) as $trackUuid) {
            $track = $this->lock((string) $trackUuid);
            if ($track !== null) {
                $this->merge($track, 'packing', $actorUuid, $key . '.' . $merged, $requestId, $now);
                $merged++;
            }
        }
        return $merged;
    }

    /** @param array<string, mixed> $track */
    private function merge(array $track, string $reason, string $actorUuid, string $key, string $requestId, string $now): void
    {
        $trackUuid = (string) $track['track_uuid'];
        $units = $this->pdo->prepare("SELECT unit_uuid FROM production_work_units WHERE track_uuid = ? AND status = 'active'");
        $units->execute([$trackUuid]);
        $unitUuids = $units->fetchAll(PDO::FETCH_COLUMN);
        $this->pdo->prepare('UPDATE production_work_units SET track_uuid = NULL, updated_at = ? WHERE track_uuid = ?')->execute([$now, $trackUuid]);
        $version = (int) $track['version'];
        $statement = $this->pdo->prepare("UPDATE production_tracks SET status = 'merged', merged_at = ?, merged_reason = ?, owner_employee_uuid = NULL, claimed_at = NULL, version = version + 1, updated_at = ?
             WHERE track_uuid = ? AND version = ? AND status = 'active' AND open_exception_uuid IS NULL");
        $statement->execute([$now, $reason, $now, $trackUuid, $version]);
        if ($statement->rowCount() !== 1) {
            throw new ApiException(409, 'TRACK_CHANGED', 'The piece in rework changed. Reload it before continuing.');
        }
        $this->event($trackUuid, (string) $track['order_uuid'], 'merged', $actorUuid, (string) $track['stage_id'], null, $track['owner_employee_uuid'] === null ? null : (string) $track['owner_employee_uuid'], null,
            $version, (int) $track['rework_cycle'], null, ['reason' => $reason, 'units' => $unitUuids], $requestId, $key . '.merge.' . $trackUuid, $now);
    }

    /** Active tracks of the given orders, oldest first. @param list<string> $orderUuids @return array<string, list<array<string, mixed>>> */
    public function activeFor(array $orderUuids): array
    {
        if ($orderUuids === []) {
            return [];
        }
        try {
            $statement = $this->pdo->prepare(
                "SELECT t.*, e.display_name AS owner_name, s.display_name AS stage_label, s.ordinal AS stage_ordinal, x.exception_number AS open_exception_number, x.status AS open_exception_status
                 FROM production_tracks t
                 LEFT JOIN employees e ON e.employee_uuid = t.owner_employee_uuid
                 LEFT JOIN production_stages s ON s.stage_id = t.stage_id
                 LEFT JOIN production_exceptions x ON x.exception_uuid = t.open_exception_uuid
                 WHERE t.status = 'active' AND t.order_uuid IN (" . implode(',', array_fill(0, count($orderUuids), '?')) . ') ORDER BY t.track_number',
            );
            $statement->execute(array_values(array_unique($orderUuids)));
        } catch (\PDOException) {
            return [];
        }
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['order_uuid']][] = $row;
        }
        return $result;
    }

    /** Track lineage from the given track up to its first ancestor. @return list<string> */
    public function lineage(string $trackUuid): array
    {
        $chain = [];
        $current = $trackUuid;
        while ($current !== null && count($chain) < 50) {
            $chain[] = $current;
            $statement = $this->pdo->prepare('SELECT parent_track_uuid FROM production_tracks WHERE track_uuid = ?');
            $statement->execute([$current]);
            $parent = $statement->fetchColumn();
            $current = is_string($parent) ? $parent : null;
        }
        return $chain;
    }
}
