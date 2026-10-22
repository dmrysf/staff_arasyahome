<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Integration\SourceDefinition;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Records and reports the Confirmare activation of a signed source (operator CLI bin/workflow-cohort.php only; there
 * is no HTTP route). An activation is an append-only row stamped with the server clock: the source's actual
 * activation instant, never typed in and never earlier than the business transition date. A suspension is another
 * row; nothing is ever updated or deleted, and no order is ever reclassified by either.
 *
 * Activation requires all of: the Confirmare workflow exists (ConfirmationGate::WORKFLOW_READY, false in Phase 1),
 * the source is permitted (ARASYA_SOURCE_CONFIRMARE_<SOURCE>=on, which needs production, QR and document authority
 * ENFORCE), the source is enabled and active for ingestion, the business transition date has passed and the source
 * is not active already. The insert locks the source's order_sources row first, the row every new-order ingestion
 * of that source locks before it classifies, so no order is classified against a half-recorded activation.
 *
 * The diagnosis is read-only (a read-only transaction): it lists, by order number and global id only, the orders a
 * person has to look at and changes nothing.
 */
final readonly class WorkflowCohortActivation
{
    public function __construct(private PDO $pdo, private Clock $clock)
    {
    }

    /** A schedule leaves the sources at least this long to report their armed fence (heartbeat every 5 minutes). */
    public const MIN_SCHEDULE_LEAD_SECONDS = 900;
    /** The commit must happen at least this long before the scheduled instant. */
    public const MIN_COMMIT_LEAD_SECONDS = 60;
    /** The source's arming report must be this recent when the activation is committed. */
    public const ARMING_REPORT_MAX_AGE_SECONDS = 900;

    /**
     * Step 1 of the first activation: schedule it for the future instant $at (server clock + at least 15 minutes, never
     * before the business date). From now on every heartbeat reports `scheduled` with $at, and YD SOFT arms its local
     * fence for $at. Nothing is classified by a schedule.
     *
     * @return array<string, mixed>
     */
    public function schedule(?SourceDefinition $source, DateTimeImmutable $at, string $actor, ?string $reason, string $requestId, bool $workflowReady = ConfirmationGate::WORKFLOW_READY): array
    {
        $this->requireActivatable($source, $workflowReady);
        $at = $at->setTimezone(new DateTimeZone('UTC'));
        return $this->record($source->key, WorkflowCohort::ACTION_SCHEDULED, $actor, $reason, $requestId, function (DateTimeImmutable $now) use ($source, $at): DateTimeImmutable {
            if (WorkflowCohort::firstActivation($this->pdo, $source->key, true) !== null) {
                throw new ApiException(409, 'CONFIRMARE_ALREADY_ACTIVATED', 'The source was already activated once; a suspended source is resumed, never scheduled again.');
            }
            if (WorkflowCohort::pendingSchedule($this->pdo, $source->key, true) !== null) {
                throw new ApiException(409, 'CONFIRMARE_ALREADY_SCHEDULED', 'An activation is already scheduled; cancel it first.');
            }
            if ($at < WorkflowCohort::cutover() || $at->getTimestamp() < $now->getTimestamp() + self::MIN_SCHEDULE_LEAD_SECONDS) {
                throw new ApiException(422, 'CONFIRMARE_SCHEDULE_TOO_SOON', 'Schedule the activation at least 15 minutes ahead and never before 2026-10-22 00:00 Europe/Bucharest.');
            }
            return $at;
        });
    }

    /** Withdraws a schedule that was not committed yet. @return array<string, mixed> */
    public function cancelSchedule(string $sourceKey, string $actor, string $reason, string $requestId): array
    {
        if (trim($reason) === '') {
            throw new ApiException(422, 'REASON_REQUIRED', 'A cancellation needs a reason.');
        }
        return $this->record($sourceKey, WorkflowCohort::ACTION_CANCELLED, $actor, $reason, $requestId, function (DateTimeImmutable $now) use ($sourceKey): DateTimeImmutable {
            if (WorkflowCohort::pendingSchedule($this->pdo, $sourceKey, true) === null) {
                throw new ApiException(409, 'CONFIRMARE_NOT_SCHEDULED', 'No uncommitted activation is scheduled.');
            }
            return $now;
        });
    }

    /**
     * Step 2 of the first activation: commit the scheduled instant. Refused unless the source reported, after the
     * schedule and recently, that its local fence is armed at or before the scheduled instant, and the instant is still
     * at least a minute ahead. Every order submitted at or after the instant is then Confirmare, and the source already
     * refuses legacy production for it locally: there is no unprotected window.
     *
     * @return array<string, mixed>
     */
    public function activate(?SourceDefinition $source, string $actor, ?string $reason, string $requestId, bool $workflowReady = ConfirmationGate::WORKFLOW_READY): array
    {
        $this->requireActivatable($source, $workflowReady);
        return $this->record($source->key, WorkflowCohort::ACTION_ACTIVATED, $actor, $reason, $requestId, function (DateTimeImmutable $now) use ($source): DateTimeImmutable {
            if (WorkflowCohort::firstActivation($this->pdo, $source->key, true) !== null) {
                throw new ApiException(409, 'CONFIRMARE_ALREADY_ACTIVATED', 'The source was already activated; use resume after a suspension.');
            }
            $at = WorkflowCohort::pendingSchedule($this->pdo, $source->key, true)
                ?? throw new ApiException(409, 'CONFIRMARE_NOT_SCHEDULED', 'Schedule the activation first (two-phase activation).');
            if ($at->getTimestamp() < $now->getTimestamp() + self::MIN_COMMIT_LEAD_SECONDS) {
                throw new ApiException(409, 'CONFIRMARE_SCHEDULE_LAPSED', 'The scheduled instant is less than a minute away or past; cancel and schedule again.');
            }
            $arming = $this->armingOf($source->key, true);
            $scheduledRecorded = $this->pdo->prepare("SELECT MAX(recorded_at) FROM workflow_cohort_activations WHERE source_key = ? AND action = 'scheduled'");
            $scheduledRecorded->execute([$source->key]);
            $since = new DateTimeImmutable((string) $scheduledRecorded->fetchColumn(), new DateTimeZone('UTC'));
            if ($arming === null || $arming['armedAt'] > $at || $arming['reportedAt'] < $since || $arming['reportedAt']->getTimestamp() < $now->getTimestamp() - self::ARMING_REPORT_MAX_AGE_SECONDS) {
                throw new ApiException(409, 'CONFIRMARE_SOURCE_NOT_ARMED', 'The source has not reported, since the schedule, a local Confirmare fence armed at or before the scheduled instant.');
            }
            return $at;
        });
    }

    /** Holds the source's new orders (closed, not distributed). It never hands an order back to the legacy flow. @return array<string, mixed> */
    public function suspend(string $sourceKey, string $actor, string $reason, string $requestId): array
    {
        if (trim($reason) === '') {
            throw new ApiException(422, 'REASON_REQUIRED', 'A suspension needs a reason.');
        }
        return $this->record($sourceKey, WorkflowCohort::ACTION_SUSPENDED, $actor, $reason, $requestId, function (DateTimeImmutable $now) use ($sourceKey): DateTimeImmutable {
            $latest = WorkflowCohort::activation($this->pdo, $sourceKey, true);
            if ($latest === null || $latest['action'] !== WorkflowCohort::ACTION_ACTIVATED || WorkflowCohort::heartbeatState($this->pdo, $sourceKey, $now)['state'] !== WorkflowCohort::STATE_ACTIVE) {
                throw new ApiException(409, 'CONFIRMARE_NOT_ACTIVE', 'The source is not active for Confirmare.');
            }
            return $now;
        });
    }

    /** Ends a suspension. The first activation instant stays the protection instant. @return array<string, mixed> */
    public function resume(?SourceDefinition $source, string $actor, ?string $reason, string $requestId, bool $workflowReady = ConfirmationGate::WORKFLOW_READY): array
    {
        $this->requireActivatable($source, $workflowReady);
        return $this->record($source->key, WorkflowCohort::ACTION_ACTIVATED, $actor, $reason, $requestId, function (DateTimeImmutable $now) use ($source): DateTimeImmutable {
            $latest = WorkflowCohort::activation($this->pdo, $source->key, true);
            if ($latest === null || $latest['action'] !== WorkflowCohort::ACTION_SUSPENDED) {
                throw new ApiException(409, 'CONFIRMARE_NOT_SUSPENDED', 'The source is not suspended.');
            }
            return $now;
        });
    }

    /**
     * The source's latest report of its local fence (signed heartbeat field confirmareArmedAt), or null.
     *
     * @return array{armedAt: DateTimeImmutable, reportedAt: DateTimeImmutable}|null
     */
    public function armingOf(string $sourceKey, bool $lock = false): ?array
    {
        $statement = $this->pdo->prepare('SELECT armed_at, reported_at FROM workflow_cohort_arming WHERE source_key = ?' . ($lock ? ' LOCK IN SHARE MODE' : ''));
        $statement->execute([$sourceKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? ['armedAt' => new DateTimeImmutable((string) $row['armed_at'], new DateTimeZone('UTC')), 'reportedAt' => new DateTimeImmutable((string) $row['reported_at'], new DateTimeZone('UTC'))] : null;
    }

    private function requireActivatable(?SourceDefinition $source, bool $workflowReady): void
    {
        if (!$workflowReady) {
            throw new ApiException(409, 'CONFIRMARE_WORKFLOW_NOT_READY', 'The Confirmare workflow that approves orders is not available yet; activating a source would stop its production.');
        }
        if ($source === null || !$source->confirmarePermitted) {
            throw new ApiException(409, 'CONFIRMARE_NOT_PERMITTED', 'ARASYA_SOURCE_CONFIRMARE_<SOURCE> is not on, or the source does not enforce production, QR and document authority.');
        }
        if (!$source->canIngest()) {
            throw new ApiException(409, 'SOURCE_NOT_ACTIVE', 'The source is not enabled and active for order ingestion.');
        }
    }

    /**
     * Appends one history row with the server clock, under the source row lock. $check runs inside the transaction and
     * returns the row's effective instant.
     *
     * @param callable(DateTimeImmutable): DateTimeImmutable $check
     * @return array<string, mixed>
     */
    private function record(string $sourceKey, string $action, string $actor, ?string $reason, string $requestId, callable $check): array
    {
        $actor = trim($actor);
        if ($actor === '' || mb_strlen($actor) > 120 || preg_match('/[\x00-\x1F\x7F]/', $actor) === 1) {
            throw new ApiException(422, 'ACTOR_REQUIRED', 'Name the person who records the change (at most 120 characters).');
        }
        $reason = $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 200);
        // The business date bounds the effective instant (schedule check and database CHECK), not the moment of recording:
        // a first activation may be scheduled and committed ahead of time.
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare("SELECT source_key FROM order_sources WHERE source_key = ? AND status = 'active' FOR UPDATE");
            $lock->execute([$sourceKey]);
            if ($lock->fetchColumn() === false) {
                throw new ApiException(409, 'SOURCE_INACTIVE', 'The source is not registered or inactive.');
            }
            $effective = $check($now);
            $event = Uuid::v4();
            $this->pdo->prepare('INSERT INTO workflow_cohort_activations (event_uuid, source_key, action, effective_at, actor_label, reason, request_id, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$event, $sourceKey, $action, $effective->format('Y-m-d H:i:s.u'), $actor, $reason, $requestId, $now->format('Y-m-d H:i:s.u')]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return ['sourceKey' => $sourceKey, 'action' => $action, 'effectiveAt' => $effective->format('Y-m-d\TH:i:s.u\Z'), 'recordedAt' => $now->format('Y-m-d\TH:i:s.u\Z'), 'eventUuid' => $event];
    }

    /**
     * Read-only diagnosis of one source: activation history, cohort counts and the orders that need a person.
     *
     * - transitionWindow: orders submitted (or, without evidence, received) on or after the business transition date
     *   that are not Confirmare orders: created before the source's activation, or created while it was not active.
     *   Those with production history (a document, a later stage, an owner, completed) were processed through the
     *   established flow and stay there; the others are listed for review. Nothing is adopted automatically.
     * - conflicts: Confirmare or undetermined orders that nevertheless show production history (only possible through
     *   an older API or a direct database change): each needs an explicit operator resolution.
     * - undetermined: orders whose submission instant could not be proven while the source was active.
     *
     * @return array<string, mixed>
     */
    public function diagnose(string $sourceKey, ?SourceDefinition $source): array
    {
        $history = $this->pdo->prepare('SELECT action, effective_at, actor_label, reason FROM workflow_cohort_activations WHERE source_key = ? ORDER BY effective_at, recorded_at');
        $history->execute([$sourceKey]);
        $events = array_map(static fn (array $row): array => [
            'action' => (string) $row['action'],
            'effectiveAt' => self::iso((string) $row['effective_at']),
            'actor' => (string) $row['actor_label'],
            'reason' => $row['reason'] === null ? null : (string) $row['reason'],
        ], $history->fetchAll(PDO::FETCH_ASSOC));
        $latest = $events === [] ? null : $events[count($events) - 1];

        $counts = $this->pdo->prepare("SELECT COALESCE(workflow_cohort, 'pre_cohort') AS cohort, COUNT(*) AS total FROM operational_orders WHERE source_key = ? GROUP BY COALESCE(workflow_cohort, 'pre_cohort')");
        $counts->execute([$sourceKey]);
        $byCohort = ['pre_cohort' => 0, WorkflowCohort::LEGACY => 0, WorkflowCohort::CONFIRMARE => 0, WorkflowCohort::UNDETERMINED => 0];
        foreach ($counts->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byCohort[(string) $row['cohort']] = (int) $row['total'];
        }

        $rows = $this->pdo->prepare(
            "SELECT o.global_order_id, o.order_number, o.production_stage_id, o.production_authority, o.document_status, o.production_owner_employee_uuid,
                    o.production_completed_at, o.operational_status, o.workflow_cohort, o.workflow_cohort_reason, o.source_submitted_at,
                    o.source_submission_evidence, o.created_at, c.status AS confirmation_status
             FROM operational_orders o LEFT JOIN order_confirmations c ON c.order_uuid = o.order_uuid
             WHERE o.source_key = ? AND (o.workflow_cohort IN ('confirmare', 'undetermined') OR COALESCE(o.source_submitted_at, o.created_at) >= ?)
             ORDER BY COALESCE(o.source_submitted_at, o.created_at), o.global_order_id",
        );
        $rows->execute([$sourceKey, WorkflowCohort::cutover()->format('Y-m-d H:i:s.u')]);
        $window = ['withProductionHistory' => [], 'withoutProductionHistory' => []];
        $conflicts = [];
        $undetermined = [];
        $awaiting = 0;
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $history = $row['document_status'] !== 'none' || $row['production_stage_id'] !== 'waiting' || $row['production_owner_employee_uuid'] !== null || $row['production_completed_at'] !== null;
            $entry = [
                'globalOrderId' => (string) $row['global_order_id'],
                'orderNumber' => (string) $row['order_number'],
                'cohort' => $row['workflow_cohort'] === null ? 'pre_cohort' : (string) $row['workflow_cohort'],
                'reason' => $row['workflow_cohort_reason'] === null ? null : (string) $row['workflow_cohort_reason'],
                'submittedAt' => $row['source_submitted_at'] === null ? null : self::iso((string) $row['source_submitted_at']),
                'evidence' => $row['source_submission_evidence'] === null ? null : (string) $row['source_submission_evidence'],
                'receivedAt' => self::iso((string) $row['created_at']),
                'stage' => (string) $row['production_stage_id'],
                'authority' => (string) $row['production_authority'],
                'documentStatus' => (string) $row['document_status'],
                'owned' => $row['production_owner_employee_uuid'] !== null,
                'completed' => $row['production_completed_at'] !== null,
                'available' => $row['operational_status'] !== 'unavailable',
            ];
            if (WorkflowCohort::needsConfirmation($row['workflow_cohort'] === null ? null : (string) $row['workflow_cohort'])) {
                if ($row['workflow_cohort'] === WorkflowCohort::UNDETERMINED) {
                    $undetermined[] = $entry;
                }
                if ($history) {
                    $conflicts[] = $entry + ['confirmation' => $row['confirmation_status'] === null ? null : (string) $row['confirmation_status']];
                } elseif ($row['workflow_cohort'] === WorkflowCohort::CONFIRMARE && $row['confirmation_status'] !== ConfirmationGate::STATUS_APPROVED) {
                    $awaiting++;
                }
                continue;
            }
            $window[$history ? 'withProductionHistory' : 'withoutProductionHistory'][] = $entry;
        }
        $view = WorkflowCohort::heartbeatState($this->pdo, $sourceKey, $this->clock->now());
        $everActivated = WorkflowCohort::firstActivation($this->pdo, $sourceKey) !== null;
        $schedule = WorkflowCohort::pendingSchedule($this->pdo, $sourceKey);
        $arming = $this->armingOf($sourceKey);
        return [
            'sourceKey' => $sourceKey,
            'businessCutover' => WorkflowCohort::BUSINESS_CUTOVER_UTC,
            'state' => $view['state'],
            'activatedAt' => $everActivated ? $view['activatedAt'] : null,
            'scheduledAt' => $schedule === null ? null : $schedule->format('Y-m-d\TH:i:s.u\Z'),
            'sourceArming' => $arming === null ? null : ['armedAt' => $arming['armedAt']->format('Y-m-d\TH:i:s.u\Z'), 'reportedAt' => $arming['reportedAt']->format('Y-m-d\TH:i:s.u\Z')],
            'permitted' => $source?->confirmarePermitted ?? false,
            'configMismatch' => $everActivated && !($source?->confirmarePermitted ?? false),
            'workflowReady' => ConfirmationGate::WORKFLOW_READY,
            'history' => $events,
            'ordersByCohort' => $byCohort,
            'awaitingConfirmation' => $awaiting,
            'transitionWindow' => $window,
            'undetermined' => $undetermined,
            'conflicts' => $conflicts,
        ];
    }

    private static function iso(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
