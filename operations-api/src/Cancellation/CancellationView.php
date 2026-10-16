<?php

declare(strict_types=1);

namespace Arasya\Operations\Cancellation;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Read models of the cancellation control, shared by Staff and Dashboard so both show the same effective state.
 *
 * Management category (exactly one per control row, so no order is counted twice):
 *   completed_then_cancelled     production was completed before the cancellation; nothing is reopened
 *   source_cancelled             the source reports the order cancelled; production stays stopped
 *   pending_source_confirmation  stopped in Arasya, a commercial cancellation was requested and is not confirmed
 *   reactivated_awaiting_release the source reports the order active again; the stop needs an authorized release
 *   stopped                      stopped in Arasya without a source cancellation
 *   released                     an authorized release lifted the stop
 * Next action: physical_reconciliation, source_confirmation, release_decision or none.
 */
final class CancellationView
{
    public const CATEGORIES = ['completed_then_cancelled', 'source_cancelled', 'pending_source_confirmation', 'reactivated_awaiting_release', 'stopped', 'released'];

    private function __construct()
    {
    }

    /**
     * Staff view of an effective stop from a repository row (stop_* columns), or null when production is not stopped.
     * Carries no customer data and no source status text.
     * @param array<string, mixed> $row @return array<string, mixed>|null
     */
    public static function staff(array $row): ?array
    {
        if (($row['production_stopped_at'] ?? null) === null || ($row['stop_origin'] ?? null) === null) {
            return null;
        }
        return [
            'state' => 'stopped',
            'reason' => (string) $row['stop_reason'],
            'origin' => (string) $row['stop_origin'],
            'sourceStatus' => (string) $row['stop_source_status'],
            'sourceRequest' => (string) $row['stop_request'],
            'phase' => (string) $row['stop_phase'],
            'physical' => (string) $row['stop_physical'],
            'since' => self::iso((string) $row['production_stopped_at']),
            // API 2.36.0: awaiting_confirmation | attention | confirmed, or null without an outbound request (never details).
            'sync' => Sync\SyncState::staffView(isset($row['stop_sync']) ? (string) $row['stop_sync'] : null),
            'canContinue' => false,
            'managementAttention' => true,
        ];
    }

    /** @param array<string, mixed> $control a control row */
    public static function category(array $control): string
    {
        if ($control['hold_status'] === CancellationControl::RELEASED) {
            return 'released';
        }
        return match (true) {
            $control['production_phase'] === 'completed' => 'completed_then_cancelled',
            $control['source_status'] === 'cancelled' => 'source_cancelled',
            $control['source_status'] === 'reactivated' => 'reactivated_awaiting_release',
            $control['source_request_status'] === 'pending' => 'pending_source_confirmation',
            default => 'stopped',
        };
    }

    /** @param array<string, mixed> $control */
    public static function nextAction(array $control): string
    {
        $category = self::category($control);
        if ($category === 'released') {
            return 'none';
        }
        if ($control['physical_status'] === 'pending') {
            return 'physical_reconciliation';
        }
        return match ($category) {
            'pending_source_confirmation' => 'source_confirmation',
            'reactivated_awaiting_release', 'stopped' => 'release_decision',
            default => 'none',
        };
    }

    /**
     * Management view of a control row. Employee names are resolved by the caller's query (aliases *_name).
     * @param array<string, mixed> $control @param array<string, mixed> $order locked or read order columns
     * @return array<string, mixed>
     */
    public static function management(array $control, array $order, bool $syncOpen = false): array
    {
        $person = static fn (?string $id, ?string $name): ?array => $id === null ? null : ['id' => $id, 'displayName' => $name ?? $id];
        $refusal = CancellationControl::releaseRefusal($order, $control, $syncOpen);
        return [
            'state' => $control['hold_status'] === CancellationControl::HELD ? 'stopped' : 'released',
            'category' => self::category($control),
            'nextAction' => self::nextAction($control),
            'origin' => (string) $control['origin'],
            'reason' => (string) $control['reason_code'],
            'reasonNote' => $control['reason_note'] === null ? null : (string) $control['reason_note'],
            'source' => [
                'status' => (string) $control['source_status'],
                'statusCode' => $control['source_status_code'] === null ? null : (string) $control['source_status_code'],
                'statusLabel' => $control['source_status_label'] === null ? null : (string) $control['source_status_label'],
                'changedAt' => self::isoOrNull($control['source_changed_at']),
                'cancellationReceivedAt' => self::isoOrNull($control['source_cancellation_received_at']),
                'evidenceAt' => self::isoOrNull($control['source_evidence_at']),
                'request' => (string) $control['source_request_status'],
                'requestedAt' => self::isoOrNull($control['source_requested_at']),
                // Without the synchronization (before 034) a pending request is recorded only; with it, the operation state.
                'outbound' => 'not_sent',
            ],
            'atStop' => [
                'phase' => (string) $control['production_phase'],
                'stageId' => (string) $control['stage_id_at_hold'],
                'owner' => $person($control['owner_employee_uuid_at_hold'] ?? null, $control['owner_at_hold_name'] ?? null),
                'exceptionId' => $control['exception_uuid_at_hold'] === null ? null : (string) $control['exception_uuid_at_hold'],
                'productionVersion' => (int) $control['production_version_at_hold'],
                'completedAt' => self::isoOrNull($control['production_completed_at_hold']),
                'cuttingStartedAt' => self::isoOrNull($control['cutting_started_at_hold']),
                'tracks' => $control['active_tracks_at_hold'] === null ? [] : (json_decode((string) $control['active_tracks_at_hold'], true) ?: []),
            ],
            'physical' => [
                'status' => (string) $control['physical_status'],
                'outcome' => $control['physical_outcome'] === null ? null : (string) $control['physical_outcome'],
                'note' => $control['physical_note'] === null ? null : (string) $control['physical_note'],
                'scan' => $control['physical_scan'] === null ? null : (string) $control['physical_scan'],
                'verifiedBy' => $person($control['physical_verified_by_employee_uuid'] ?? null, $control['physical_by_name'] ?? null),
                'verifiedAt' => self::isoOrNull($control['physical_verified_at']),
            ],
            'heldAt' => self::iso((string) $control['held_at']),
            'heldBy' => $person($control['held_by_employee_uuid'] ?? null, $control['held_by_name'] ?? null),
            'releasedAt' => self::isoOrNull($control['released_at']),
            'releasedBy' => $person($control['released_by_employee_uuid'] ?? null, $control['released_by_name'] ?? null),
            'releaseReason' => $control['release_reason'] === null ? null : (string) $control['release_reason'],
            'release' => ['possible' => $refusal === null, 'refusal' => $refusal],
            'version' => (int) $control['version'],
        ];
    }

    public static function iso(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function isoOrNull(mixed $value): ?string
    {
        return $value === null ? null : self::iso((string) $value);
    }
}
