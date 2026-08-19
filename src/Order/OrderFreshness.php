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
}
