<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

use DateTimeImmutable;

final readonly class EmployeeOrderRelation
{
    public function __construct(
        public string $employeeUuid,
        public string $type, // 'claimed', 'assigned', 'updated', 'handover_in', 'handover_out', 'completed'
        public string $status, // 'active', 'inactive'
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $lastActionAt,
    ) {
    }
}
