<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Workflow cohort of a signed WooCommerce source order (Confirmare cutover, API 2.37.0).
 *
 * Owner decision (2026-10-22): genuinely new Trend Home and Outlet Perdele orders go through the Confirmare
 * front-office workflow before production; every older order keeps its established handling. The business
 * transition date is 2026-10-22 00:00 Europe/Bucharest (2026-10-21T21:00:00Z), but the decisive instant is the
 * separately recorded ACTUAL activation of each source (workflow_cohort_activations), which can never be earlier
 * than that date. Orders the source submitted before its activation stay legacy, even when they were created on or
 * after 22 October; nothing is ever migrated or reclassified.
 *
 * Arasya is the only classifier. The cohort is decided once, inside the transaction that creates the canonical
 * order, from two immutable inputs: the source's activation history (read under a shared lock, so an activation
 * and an ingestion can never interleave) and the source's submission evidence (the first instant WooCommerce held
 * the order in a submitted status, recorded by YD SOFT; never the last update, payment, shipping or completion
 * time, and never the creation time of a checkout draft). Later events never change it.
 *
 * Rule, for a source whose latest activation row is `activated` with effective instant A:
 * - submission evidence at T >= A                   → confirmare (submitted_after_activation)
 * - submission evidence at T <  A                   → legacy     (submitted_before_activation)
 * - no evidence, an unknown kind, or an inconsistent
 *   or future instant                               → undetermined (closed, reported; never guessed)
 * A source without activation (or suspended) creates legacy orders (not_activated / suspended).
 */
final class WorkflowCohort
{
    /** 2026-10-22 00:00:00 Europe/Bucharest (EEST, UTC+3): no activation can be earlier. */
    public const BUSINESS_CUTOVER_UTC = '2026-10-21T21:00:00Z';
    public const BUSINESS_TIMEZONE = 'Europe/Bucharest';

    public const LEGACY = 'legacy';
    public const CONFIRMARE = 'confirmare';
    public const UNDETERMINED = 'undetermined';

    public const REASON_NOT_ACTIVATED = 'not_activated';
    public const REASON_SUSPENDED = 'suspended';
    public const REASON_BEFORE_ACTIVATION = 'submitted_before_activation';
    public const REASON_AFTER_ACTIVATION = 'submitted_after_activation';
    public const REASON_EVIDENCE_MISSING = 'submission_evidence_missing';
    public const REASON_EVIDENCE_INCONSISTENT = 'submission_evidence_inconsistent';
    public const REASON_EVIDENCE_FUTURE = 'submission_evidence_future';

    public const ACTION_ACTIVATED = 'activated';
    public const ACTION_SUSPENDED = 'suspended';

    /**
     * Kinds of submission evidence a source may state (order.submission.evidence):
     * - checkout / store_api: the classic or block checkout submitted the order (WooCommerce's own submission hooks);
     * - admin / rest_api: an administrator or an authenticated API client created it directly in a submitted status;
     * - observed: YD SOFT first saw it in a submitted status (any other path; at most one request late);
     * - predates_recorder: the order already existed in a submitted status before YD SOFT started recording, so its
     *   WooCommerce creation instant is used (only ever earlier than an activation).
     */
    public const EVIDENCE_KINDS = ['checkout', 'store_api', 'admin', 'rest_api', 'observed', 'predates_recorder'];

    /** A source clock may run ahead of Arasya by this much before its evidence counts as being in the future. */
    public const FUTURE_TOLERANCE_SECONDS = 300;

    private function __construct()
    {
    }

    public static function cutover(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::BUSINESS_CUTOVER_UTC);
    }

    /**
     * @param array{action: string, effectiveAt: DateTimeImmutable}|null $activation the source's latest activation row
     * @param array{submittedAt: DateTimeImmutable, evidence: string}|null $submission the source's submission evidence
     * @return array{cohort: string, reason: string}
     */
    public static function classify(?array $activation, ?array $submission, DateTimeImmutable $now): array
    {
        if ($activation === null) {
            return ['cohort' => self::LEGACY, 'reason' => self::REASON_NOT_ACTIVATED];
        }
        if ($activation['action'] !== self::ACTION_ACTIVATED) {
            return ['cohort' => self::LEGACY, 'reason' => self::REASON_SUSPENDED];
        }
        if ($submission === null || !in_array($submission['evidence'], self::EVIDENCE_KINDS, true)) {
            return ['cohort' => self::UNDETERMINED, 'reason' => self::REASON_EVIDENCE_MISSING];
        }
        $at = $submission['submittedAt'];
        if ($at->getTimestamp() > $now->getTimestamp() + self::FUTURE_TOLERANCE_SECONDS) {
            return ['cohort' => self::UNDETERMINED, 'reason' => self::REASON_EVIDENCE_FUTURE];
        }
        if (self::micro($at) < self::micro($activation['effectiveAt'])) {
            return ['cohort' => self::LEGACY, 'reason' => self::REASON_BEFORE_ACTIVATION];
        }
        // A WooCommerce creation instant stands in only for orders that existed before the recorder: it can never be
        // at or after an activation, which needs the recorder. Treat the contradiction as missing evidence.
        if ($submission['evidence'] === 'predates_recorder') {
            return ['cohort' => self::UNDETERMINED, 'reason' => self::REASON_EVIDENCE_INCONSISTENT];
        }
        return ['cohort' => self::CONFIRMARE, 'reason' => self::REASON_AFTER_ACTIVATION];
    }

    /** Production is closed for this cohort until the Confirmare workflow approves the order. */
    public static function needsConfirmation(?string $cohort): bool
    {
        return $cohort === self::CONFIRMARE || $cohort === self::UNDETERMINED;
    }

    /**
     * The latest activation row of a source, or null. With $lock the caller's transaction first locks the source's
     * order_sources row, the same row an activation locks before it inserts, so an activation is either fully before
     * or fully after this read. Ingestion already updates that row before it commits (last contact), so the lock adds
     * no new ordering: order row first, source row second.
     *
     * @return array{action: string, effectiveAt: DateTimeImmutable}|null
     */
    public static function activation(PDO $pdo, string $sourceKey, bool $lock = false): ?array
    {
        if ($lock) {
            $source = $pdo->prepare('SELECT source_key FROM order_sources WHERE source_key = ? FOR UPDATE');
            $source->execute([$sourceKey]);
            $source->fetchAll();
        }
        // A locking read sees the latest committed rows: a plain read would use the transaction's snapshot, which an
        // ingestion may have opened (its first read) before the activation it waited for was committed.
        $statement = $pdo->prepare('SELECT action, effective_at FROM workflow_cohort_activations WHERE source_key = ? ORDER BY effective_at DESC, recorded_at DESC LIMIT 1' . ($lock ? ' LOCK IN SHARE MODE' : ''));
        $statement->execute([$sourceKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return ['action' => (string) $row['action'], 'effectiveAt' => new DateTimeImmutable((string) $row['effective_at'], new DateTimeZone('UTC'))];
    }

    /**
     * The heartbeat view of a source's activation: `active` with its instant, `suspended`, or `inactive`.
     *
     * @return array{state: string, activatedAt: string|null}
     */
    public static function heartbeatState(PDO $pdo, string $sourceKey): array
    {
        $activation = self::activation($pdo, $sourceKey);
        if ($activation === null) {
            return ['state' => 'inactive', 'activatedAt' => null];
        }
        if ($activation['action'] !== self::ACTION_ACTIVATED) {
            return ['state' => 'suspended', 'activatedAt' => null];
        }
        return ['state' => 'active', 'activatedAt' => $activation['effectiveAt']->format('Y-m-d\TH:i:s.u\Z')];
    }

    private static function micro(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
