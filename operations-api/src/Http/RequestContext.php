<?php

declare(strict_types=1);

namespace Arasya\Operations\Http;

final class RequestContext
{
    private ?string $employeeUuid = null;

    public function reset(): void
    {
        $this->employeeUuid = null;
    }

    public function authenticatedAs(string $employeeUuid): void
    {
        $this->employeeUuid = $employeeUuid;
    }

    /** @return array<string, string> */
    public function logContext(): array
    {
        return $this->employeeUuid === null ? [] : ['employee_uuid' => $this->employeeUuid];
    }
}
