<?php

declare(strict_types=1);

namespace Arasya\Operations\Auth;

use Arasya\Operations\Employee\EmployeeIdentity;

final readonly class AuthenticatedSession
{
    public function __construct(
        public EmployeeIdentity $employee,
        public SessionRecord $session,
        public string $rawToken,
        public string $csrfToken,
    ) {
    }
}

