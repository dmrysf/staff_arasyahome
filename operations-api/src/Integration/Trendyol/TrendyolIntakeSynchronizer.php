<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

use Arasya\Operations\Database\DatabaseAdvisoryLock;
use Arasya\Operations\Order\OrderProjectionWriter;
use Arasya\Operations\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Pull-based Trendyol intake for CLI/cron only (never from a web request). Reads shipment packages with GET
 * requests and writes them into the intake inbox through TrendyolIntakeStore; it never creates a production
 * order, a QR or a document.
 *
 * It refuses to call Trendyol unless the intake row is `active` (the caller also checks the configuration
 * switch). The window starts at the cursor (initially the activation baseline) minus a ten-minute overlap and
 * is read in slices of at most two weeks (Trendyol's limit). A slice whose packages exceed the 50-page limit
 * stops the run with the cursor at the last package read, so the next run continues there. A failure never
 * moves the cursor past an unread package.
 */
final readonly class TrendyolIntakeSynchronizer
{
    public const OVERLAP_SECONDS = 600;

    public function __construct(
        private PDO $pdo,
        private TrendyolClient $client,
        private TrendyolIntakeStore $store,
        private OrderProjectionWriter $writer,
        private Clock $clock,
    ) {
    }

    /** @return array{received: int, updated: int, unchanged: int, ignored: int, deferred: int, rejected: int, pages: int, truncated: bool} */
    public function run(): array
    {
        $lock = new DatabaseAdvisoryLock($this->pdo, 'arasya_trendyol_sync');
        try {
            $lock->acquire(0);
        } catch (RuntimeException) {
            throw new RuntimeException('TRENDYOL_SYNC_ALREADY_RUNNING');
        }
        try {
            $state = $this->pdo->query('SELECT status, baseline_at, cursor_at FROM trendyol_intake_state WHERE state_id = 1')->fetch(PDO::FETCH_ASSOC);
            if (!is_array($state) || $state['status'] !== 'active' || $state['baseline_at'] === null) {
                throw new RuntimeException('TRENDYOL_INTAKE_NOT_ACTIVE');
            }
            $utc = new DateTimeZone('UTC');
            $now = $this->clock->now()->setTimezone($utc);
            $baseline = new DateTimeImmutable((string) $state['baseline_at'], $utc);
            $cursor = $state['cursor_at'] === null ? $baseline : max($baseline, new DateTimeImmutable((string) $state['cursor_at'], $utc));
            $start = $cursor->modify('-' . self::OVERLAP_SECONDS . ' seconds');
            $baselineMillis = self::millis($baseline);
            $counts = ['received' => 0, 'updated' => 0, 'unchanged' => 0, 'ignored' => 0, 'deferred' => 0, 'rejected' => 0, 'pages' => 0, 'truncated' => false];
            $runId = $this->startRun($start, $now);

            try {
                while ($start < $now) {
                    $end = min($now, $start->modify('+' . (TrendyolClient::MAX_WINDOW_SECONDS - self::OVERLAP_SECONDS) . ' seconds'));
                    $page = 0;
                    $lastModified = null;
                    do {
                        $result = $this->client->packages(self::millis($start), self::millis($end), $page);
                        $counts['pages']++;
                        foreach ($result['content'] as $raw) {
                            try {
                                $package = TrendyolPackage::fromApi(is_array($raw) ? $raw : []);
                            } catch (InvalidArgumentException) {
                                $counts['rejected']++;
                                continue;
                            }
                            $lastModified = max($lastModified ?? 0, $package->lastModifiedMillis);
                            $counts[$this->store->record($package, $baselineMillis)]++;
                        }
                        $page++;
                    } while ($page < $result['totalPages'] && $page < TrendyolClient::MAX_PAGES);

                    if ($page < $result['totalPages']) {
                        // Packages arrive ordered by modification time: continue at the last one read next run.
                        $resume = $lastModified === null ? $start : (new DateTimeImmutable('@' . intdiv($lastModified, 1000)))->setTimezone($utc);
                        $floor = $start->modify('+' . self::OVERLAP_SECONDS . ' seconds');
                        $this->saveCursor($resume > $floor ? $resume : $floor);
                        $counts['truncated'] = true;
                        break;
                    }
                    $this->saveCursor($end);
                    $start = $end->modify('-' . self::OVERLAP_SECONDS . ' seconds');
                    if ($end >= $now) {
                        break;
                    }
                }
            } catch (Throwable $error) {
                $code = $error instanceof RuntimeException && preg_match('/^TRENDYOL_[A-Z_]+$/D', $error->getMessage()) === 1 ? $error->getMessage() : 'TRENDYOL_SYNC_FAILED';
                $this->finishRun($runId, $code, $counts);
                throw new RuntimeException($code, 0, $error);
            }
            $this->writer->recordHeartbeat('trendyol');
            $this->finishRun($runId, $counts['truncated'] ? 'truncated' : 'ok', $counts);
            return $counts;
        } finally {
            $lock->release();
        }
    }

    private function startRun(DateTimeImmutable $start, DateTimeImmutable $now): int
    {
        $this->pdo->prepare("INSERT INTO trendyol_sync_runs (started_at, window_start, window_end, outcome) VALUES (?, ?, ?, 'running')")
            ->execute([$now->format('Y-m-d H:i:s.u'), $start->format('Y-m-d H:i:s.u'), $now->format('Y-m-d H:i:s.u')]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, int|bool> $counts */
    private function finishRun(int $runId, string $outcome, array $counts): void
    {
        $finished = $this->clock->now()->format('Y-m-d H:i:s.u');
        $this->pdo->prepare('UPDATE trendyol_sync_runs SET finished_at = ?, outcome = ?, pages = ?, received = ?, updated = ?, unchanged = ?, ignored = ?, deferred = ?, rejected = ? WHERE run_id = ?')
            ->execute([$finished, $outcome, $counts['pages'], $counts['received'], $counts['updated'], $counts['unchanged'], $counts['ignored'], $counts['deferred'], $counts['rejected'], $runId]);
        $this->pdo->prepare('UPDATE trendyol_intake_state SET last_run_at = ?, last_run_outcome = ? WHERE state_id = 1')->execute([$finished, $outcome]);
    }

    private function saveCursor(DateTimeImmutable $cursor): void
    {
        $this->pdo->prepare('UPDATE trendyol_intake_state SET cursor_at = ? WHERE state_id = 1')->execute([$cursor->format('Y-m-d H:i:s.u')]);
    }

    private static function millis(DateTimeImmutable $at): int
    {
        return intdiv((int) $at->format('Uu'), 1000);
    }
}
