<?php

declare(strict_types=1);

namespace Arasya\Operations\Audit;

interface AuditLogger
{
    /** @param array<string, scalar|null> $metadata */
    public function record(string $eventType, ?string $employeeUuid, ?string $usernameNormalized, string $ipAddress, string $userAgent, string $requestId, string $createdAt, array $metadata = []): void;
}

