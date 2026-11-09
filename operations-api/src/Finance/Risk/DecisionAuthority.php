<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance\Risk;

use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;

/**
 * Who may make a risk colour or credit limit effective: only employees whose immutable UUID was explicitly provisioned
 * in ARASYA_FINANCE_RISK_LIMIT_AUTHORITY_UUIDS. Empty means nobody. No role, permission or root status substitutes, and
 * nothing here matches names or usernames. The owner-only workflow itself belongs to the next sprint.
 */
final readonly class DecisionAuthority
{
    /** @param list<string> $employeeUuids */
    public function __construct(private array $employeeUuids)
    {
    }

    public function allows(EmployeeIdentity $actor): bool
    {
        return in_array($actor->employeeUuid, $this->employeeUuids, true);
    }

    public function require(EmployeeIdentity $actor): void
    {
        if (!$this->allows($actor)) {
            throw new ApiException(403, 'RISK_LIMIT_AUTHORITY_REQUIRED', 'Only the explicitly provisioned decision makers can change an effective risk colour or credit limit.');
        }
    }
}
