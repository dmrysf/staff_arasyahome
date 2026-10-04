<?php

declare(strict_types=1);

namespace Arasya\Operations\Security;

interface ApiRateLimiter
{
    /** Records one hit and returns false once the subject exceeded the scope's fixed-window limit. */
    public function hit(string $scope, string $subject, int $limit, int $windowSeconds): bool;
}
