<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

use Arasya\Operations\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * The explicit activation control of Trendyol intake (trendyol_intake_state, one row). Operator CLI only.
 *
 * Intake reads Trendyol only when ALL of these hold: credentials are configured, ARASYA_TRENDYOL_INTAKE is
 * `enabled`, and this row is `active` with a baseline. Activation records the baseline instant: packages
 * ordered before it are historical and never become intake work. The baseline can only move forward and can
 * never be set more than ACTIVATION_SLACK_SECONDS in the past, so an activation can never import history.
 */
final readonly class TrendyolIntakeState
{
    public const ACTIVATION_SLACK_SECONDS = 300;

    public function __construct(private PDO $pdo, private Clock $clock)
    {
    }

    /** @return array{status: string, baselineAt: string|null, cursorAt: string|null, activatedAt: string|null, activatedBy: string|null, lastRunAt: string|null, lastRunOutcome: string|null} */
    public function read(): array
    {
        $row = $this->pdo->query('SELECT status, baseline_at, cursor_at, activated_at, activated_by, last_run_at, last_run_outcome FROM trendyol_intake_state WHERE state_id = 1')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('TRENDYOL_INTAKE_STATE_MISSING');
        }
        return [
            'status' => (string) $row['status'],
            'baselineAt' => $row['baseline_at'] === null ? null : (string) $row['baseline_at'],
            'cursorAt' => $row['cursor_at'] === null ? null : (string) $row['cursor_at'],
            'activatedAt' => $row['activated_at'] === null ? null : (string) $row['activated_at'],
            'activatedBy' => $row['activated_by'] === null ? null : (string) $row['activated_by'],
            'lastRunAt' => $row['last_run_at'] === null ? null : (string) $row['last_run_at'],
            'lastRunOutcome' => $row['last_run_outcome'] === null ? null : (string) $row['last_run_outcome'],
        ];
    }

    /**
     * First activation (or a forward-only re-baseline while paused). The cursor starts at the baseline.
     */
    public function activate(DateTimeImmutable $baseline, string $operator): array
    {
        $operator = self::operator($operator);
        $now = $this->clock->now();
        $baseline = $baseline->setTimezone(new DateTimeZone('UTC'));
        if ($baseline < $now->modify('-' . self::ACTIVATION_SLACK_SECONDS . ' seconds')) {
            throw new RuntimeException('TRENDYOL_BASELINE_IN_PAST');
        }
        if ($baseline > $now->modify('+7 days')) {
            throw new RuntimeException('TRENDYOL_BASELINE_TOO_FAR');
        }
        return $this->change(function (array $state) use ($baseline, $operator, $now): array {
            if ($state['status'] === 'active') {
                throw new RuntimeException('TRENDYOL_INTAKE_ALREADY_ACTIVE');
            }
            if ($state['baseline_at'] !== null && $baseline->format('Y-m-d H:i:s.u') < (string) $state['baseline_at']) {
                throw new RuntimeException('TRENDYOL_BASELINE_BACKWARDS');
            }
            $sql = $baseline->format('Y-m-d H:i:s.u');
            $this->pdo->prepare("UPDATE trendyol_intake_state SET status = 'active', baseline_at = ?, cursor_at = ?, activated_at = ?, activated_by = ?, changed_at = ?, changed_by = ? WHERE state_id = 1")
                ->execute([$sql, $sql, $now->format('Y-m-d H:i:s.u'), $operator, $now->format('Y-m-d H:i:s.u'), $operator]);
            return ['intake_activated', ['baselineAt' => $sql, 'previousBaselineAt' => $state['baseline_at']]];
        }, $operator);
    }

    public function pause(string $operator): array
    {
        $operator = self::operator($operator);
        return $this->change(function (array $state) use ($operator): array {
            if ($state['status'] !== 'active') {
                throw new RuntimeException('TRENDYOL_INTAKE_NOT_ACTIVE');
            }
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $this->pdo->prepare("UPDATE trendyol_intake_state SET status = 'paused', changed_at = ?, changed_by = ? WHERE state_id = 1")->execute([$now, $operator]);
            return ['intake_paused', []];
        }, $operator);
    }

    /** Resumes from the stored cursor; packages changed while paused are read on the next run. */
    public function resume(string $operator): array
    {
        $operator = self::operator($operator);
        return $this->change(function (array $state) use ($operator): array {
            if ($state['status'] !== 'paused') {
                throw new RuntimeException('TRENDYOL_INTAKE_NOT_PAUSED');
            }
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $this->pdo->prepare("UPDATE trendyol_intake_state SET status = 'active', changed_at = ?, changed_by = ? WHERE state_id = 1")->execute([$now, $operator]);
            return ['intake_resumed', []];
        }, $operator);
    }

    /** @param callable(array<string, mixed>): array{0: string, 1: array<string, mixed>} $change */
    private function change(callable $change, string $operator): array
    {
        $this->pdo->beginTransaction();
        try {
            $state = $this->pdo->query('SELECT * FROM trendyol_intake_state WHERE state_id = 1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
            if (!is_array($state)) {
                throw new RuntimeException('TRENDYOL_INTAKE_STATE_MISSING');
            }
            [$action, $details] = $change($state);
            $this->pdo->prepare('INSERT INTO trendyol_intake_events (package_id, action, actor_label, details, occurred_at) VALUES (NULL, ?, ?, ?, ?)')
                ->execute([$action, $operator, json_encode($details, JSON_THROW_ON_ERROR), $this->clock->now()->format('Y-m-d H:i:s.u')]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $this->read();
    }

    private static function operator(string $operator): string
    {
        $operator = trim($operator);
        if (preg_match('/^[\p{L}\p{N} ._@-]{2,120}$/uD', $operator) !== 1) {
            throw new RuntimeException('TRENDYOL_OPERATOR_REQUIRED');
        }
        return $operator;
    }
}
