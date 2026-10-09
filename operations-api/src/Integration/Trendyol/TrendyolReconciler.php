<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

use Arasya\Operations\Database\DatabaseAdvisoryLock;
use Arasya\Operations\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Read-only reconciliation of packages Arasya already knows (CLI/cron only, never a web request).
 *
 * The modification-time synchronization re-reads a 30-minute overlap; a marketplace change that becomes visible later
 * than that would otherwise be missed until the package changes again. This re-reads every pending package and every
 * package released in the last RELEASED_DAYS days by ID (`shipmentPackageIds`, GET only) and records the answer
 * through TrendyolIntakeStore, so it follows exactly the synchronization rules: a cancellation makes the production
 * order unavailable, a line, delivery or split change after approval is flagged for a person, and a pending package
 * follows its marketplace class. It never creates a package or a production order, never touches stages, items or
 * documents, and is idempotent (an unchanged package stays `unchanged`).
 *
 * It shares the synchronization lock and refuses to run unless the intake is `active`, so it stays inactive until the
 * owner activates intake and schedules it.
 */
final readonly class TrendyolReconciler
{
    public const RELEASED_DAYS = 45;
    public const MAX_PACKAGES = 1000;

    public function __construct(
        private PDO $pdo,
        private TrendyolClient $client,
        private TrendyolIntakeStore $store,
        private Clock $clock,
    ) {
    }

    /** @return array{checked: int, updated: int, unchanged: int, missing: int, rejected: int, requests: int} */
    public function run(): array
    {
        $lock = new DatabaseAdvisoryLock($this->pdo, 'arasya_trendyol_sync');
        try {
            $lock->acquire(0);
        } catch (RuntimeException) {
            throw new RuntimeException('TRENDYOL_SYNC_ALREADY_RUNNING');
        }
        try {
            $state = $this->pdo->query('SELECT status, baseline_at FROM trendyol_intake_state WHERE state_id = 1')->fetch(PDO::FETCH_ASSOC);
            if (!is_array($state) || $state['status'] !== 'active' || $state['baseline_at'] === null) {
                throw new RuntimeException('TRENDYOL_INTAKE_NOT_ACTIVE');
            }
            $utc = new DateTimeZone('UTC');
            $baselineMillis = intdiv((int) (new DateTimeImmutable((string) $state['baseline_at'], $utc))->format('Uu'), 1000);
            $since = $this->clock->now()->setTimezone($utc)->modify('-' . self::RELEASED_DAYS . ' days')->format('Y-m-d H:i:s.u');
            $statement = $this->pdo->prepare(
                "SELECT package_id FROM trendyol_packages
                 WHERE intake_status = 'pending' OR (intake_status = 'released' AND released_at >= ?)
                 ORDER BY last_seen_at ASC, package_id ASC LIMIT " . self::MAX_PACKAGES,
            );
            $statement->execute([$since]);
            $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
            $counts = ['checked' => 0, 'updated' => 0, 'unchanged' => 0, 'missing' => 0, 'rejected' => 0, 'requests' => 0];
            foreach (array_chunk($ids, TrendyolClient::MAX_IDS_PER_REQUEST) as $chunk) {
                $counts['requests']++;
                $found = $this->client->packagesByIds($chunk);
                foreach ($chunk as $id) {
                    $counts['checked']++;
                    if (!isset($found[$id])) {
                        // Not returned by Trendyol: nothing is changed; a person sees the package as it was.
                        $counts['missing']++;
                        continue;
                    }
                    try {
                        $package = TrendyolPackage::fromApi($found[$id]);
                    } catch (InvalidArgumentException) {
                        $counts['rejected']++;
                        continue;
                    }
                    $outcome = $this->store->record($package, $baselineMillis);
                    $counts[$outcome === 'updated' ? 'updated' : 'unchanged']++;
                }
            }
            return $counts;
        } finally {
            $lock->release();
        }
    }
}
