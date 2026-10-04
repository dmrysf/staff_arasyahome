<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

use DateTimeImmutable;

final readonly class OrderFreshness
{
    public function __construct(
        public string $status, // 'fresh', 'stale', 'source_unavailable'
        public DateTimeImmutable $sourceChangedAt,
        public DateTimeImmutable $lastSourceSeenAt,
    ) {
    }

    /**
     * Deterministic freshness from the source's last successful contact
     * (event or signed heartbeat). Recovery is automatic on the next contact.
     */
    public static function classify(string $sourceStatus, DateTimeImmutable $lastContact, DateTimeImmutable $now, int $freshSeconds, int $unavailableSeconds): string
    {
        if ($sourceStatus !== 'active') {
            return 'source_unavailable';
        }
        $age = $now->getTimestamp() - $lastContact->getTimestamp();
        if ($age <= $freshSeconds) {
            return 'fresh';
        }
        return $age <= $unavailableSeconds ? 'stale' : 'source_unavailable';
    }
}
