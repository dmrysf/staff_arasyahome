<?php

declare(strict_types=1);

namespace Arasya\Operations\Auth;

interface LoginRateLimiter
{
    public function isAllowed(string $usernameNormalized, string $ipAddress, string $now): bool;

    public function recordFailure(string $usernameNormalized, string $ipAddress, string $now): void;

    public function recordSuccess(string $usernameNormalized, string $ipAddress, string $now): void;
}

