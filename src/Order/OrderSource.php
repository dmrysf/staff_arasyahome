<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

final readonly class OrderSource
{
    public function __construct(
        public string $sourceKey,
        public string $sourceType,
        public string $displayName,
        public int $schemaVersion,
        public string $status,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
