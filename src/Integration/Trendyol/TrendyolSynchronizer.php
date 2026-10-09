<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

use Arasya\Operations\Database\DatabaseAdvisoryLock;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Order\OrderProjectionWriter;
use Arasya\Operations\Support\Clock;
use InvalidArgumentException;
use PDO;

/**
 * Pull-based Trendyol synchronization for CLI/cron only (never from a web request).
 * A persisted cursor with a ten-minute overlap plus idempotent receipts makes
 * restarts and reconnection deterministic.
 */
final readonly class TrendyolSynchronizer
{
    private const OVERLAP_SECONDS = 600;
    private const INITIAL_LOOKBACK_DAYS = 14;
    private const MAX_PAGES = 50;

    public function __construct(
        private PDO $pdo,
        private TrendyolClient $client,
        private OrderProjectionWriter $writer,
        private Clock $clock,
    ) {
    }

    /** @return array{applied: int, duplicate: int, out_of_order: int, rejected: int} */
    public function run(): array
    {
        $lock = new DatabaseAdvisoryLock($this->pdo, 'arasya_trendyol_sync');
        $lock->acquire(0);
        try {
            $now = $this->clock->now();
            $cursor = $this->pdo->query("SELECT sync_cursor_at FROM order_sources WHERE source_key = 'trendyol'")->fetchColumn();
            $start = is_string($cursor)
                ? (new \DateTimeImmutable($cursor, new \DateTimeZone('UTC')))->modify('-' . self::OVERLAP_SECONDS . ' seconds')
                : $now->modify('-' . self::INITIAL_LOOKBACK_DAYS . ' days');
            $counts = ['applied' => 0, 'duplicate' => 0, 'out_of_order' => 0, 'rejected' => 0];
            $page = 0;
            $lastModifiedMillis = null;
            do {
                $result = $this->client->packages($start->getTimestamp() * 1000, $now->getTimestamp() * 1000, $page);
                foreach ($result['content'] as $package) {
                    if (is_array($package) && is_int($package['lastModifiedDate'] ?? null)) {
                        $lastModifiedMillis = max($lastModifiedMillis ?? 0, $package['lastModifiedDate']);
                    }
                    try {
                        $outcome = $this->writer->apply(TrendyolOrderMapper::map(is_array($package) ? $package : []));
                        $counts[$outcome]++;
                    } catch (InvalidArgumentException | ApiException) {
                        $counts['rejected']++;
                    }
                }
                $page++;
            } while ($page < $result['totalPages'] && $page < self::MAX_PAGES);

            // Packages arrive ordered by modification time. When the page limit stops a run early, the cursor
            // stays at the last package read so the next run continues there instead of skipping unread pages.
            $cursorAt = $now;
            if ($page < $result['totalPages']) {
                $cursorAt = $lastModifiedMillis === null
                    ? $start->modify('+' . self::OVERLAP_SECONDS . ' seconds')
                    : (new \DateTimeImmutable('@' . intdiv($lastModifiedMillis, 1000)))->setTimezone(new \DateTimeZone('UTC'));
            }
            $this->writer->recordHeartbeat('trendyol');
            $update = $this->pdo->prepare("UPDATE order_sources SET sync_cursor_at = ? WHERE source_key = 'trendyol'");
            $update->execute([$cursorAt->format('Y-m-d H:i:s.u')]);
            return $counts;
        } finally {
            $lock->release();
        }
    }
}
