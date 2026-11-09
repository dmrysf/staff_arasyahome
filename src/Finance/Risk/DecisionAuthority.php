<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance\Risk;

use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\ApplicationAccess;
use Arasya\Operations\Employee\EmployeeRepository;

/**
 * Who may make a risk colour or credit limit effective: only employees whose immutable UUID was explicitly provisioned
 * in ARASYA_FINANCE_RISK_LIMIT_AUTHORITY_UUIDS (owner decision: Mesut Yeman and Zelal Yeman, each alone). Empty means
 * nobody. No role, permission or root status substitutes, and nothing here matches names, usernames or e-mails.
 *
 * allows() is the pure UUID test. verify() is the full server-side check used by every effective decision: the UUID is
 * provisioned, the identity loaded for this request is operationally active with the Finance application, and the
 * Central IAM registry (EmployeeRepository) read inside the decision transaction still says so (a grant revoked or an account disabled
 * after the session was loaded stops the decision).
 */
final readonly class DecisionAuthority
{
    /** @param list<string> $employeeUuids */
    public function __construct(private array $employeeUuids, private ?EmployeeRepository $registry = null)
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

    /** Full check for an effective decision; call inside the decision transaction. */
    public function verify(EmployeeIdentity $actor): void
    {
        $this->require($actor);
        if (!$actor->isOperationallyActive() || $actor->mustChangePassword || !$actor->hasApplication(ApplicationAccess::FINANCE)) {
            throw new ApiException(403, 'RISK_LIMIT_AUTHORITY_REQUIRED', 'The decision maker must be an active employee with Finance access.');
        }
        // Re-read the Central IAM registry on the decision connection: no registry means no decision (fail closed).
        $current = $this->registry?->findByUuid($actor->employeeUuid);
        if ($current === null || !$current->isOperationallyActive() || $current->mustChangePassword || !$current->hasApplication(ApplicationAccess::FINANCE)
            || $current->authorizationVersion !== $actor->authorizationVersion) {
            throw new ApiException(403, 'RISK_LIMIT_AUTHORITY_REQUIRED', 'The decision maker\'s access changed. Sign in again.');
        }
    }

    public function configured(): bool
    {
        return $this->employeeUuids !== [];
    }

    /** SHA-256 of the sorted allowlist at decision time, stored with every owner decision. */
    public function fingerprint(): string
    {
        $list = $this->employeeUuids;
        sort($list, SORT_STRING);
        return hash('sha256', implode(',', $list), true);
    }
}
