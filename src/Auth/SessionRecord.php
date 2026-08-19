<?php

declare(strict_types=1);

namespace Arasya\Operations\Auth;

use DateTimeImmutable;

final readonly class SessionRecord
{
    public function __construct(
        public string $sessionId,
        public string $employeeUuid,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $lastSeenAt,
        public ?DateTimeImmutable $revokedAt,
    ) {
    }

    public function isValidAt(DateTimeImmutable $now): bool
    {
        return $this->revokedAt === null && $this->expiresAt > $now;
    }
}

