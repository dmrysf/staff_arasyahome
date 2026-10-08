<?php

declare(strict_types=1);

namespace Arasya\Operations\Auth;

use Arasya\Operations\Audit\AuditLogger;
use Arasya\Operations\Employee\EmployeeRepository;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Security\PasswordHasher;
use Arasya\Operations\Security\SessionTokenManager;
use Arasya\Operations\Security\UsernameNormalizer;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;

final readonly class AuthenticationService
{
    public function __construct(
        private EmployeeRepository $employees,
        private SessionRepository $sessions,
        private LoginRateLimiter $rateLimiter,
        private AuditLogger $audit,
        private PasswordHasher $passwords,
        private SessionTokenManager $tokens,
        private UsernameNormalizer $usernames,
        private Clock $clock,
        private int $sessionTtlSeconds,
        private int $touchIntervalSeconds,
    ) {
    }

    public function login(string $username, string $password, string $ipAddress, string $userAgent, string $requestId): AuthResult
    {
        $normalized = $this->usernames->normalize($username);
        $now = $this->clock->now();
        $nowSql = $this->sqlTime($now);
        if ($normalized === '' || mb_strlen($normalized) > 120 || $password === '' || strlen($password) > 1024) {
            throw new ApiException(401, 'INVALID_CREDENTIALS', 'Authentication failed.');
        }
        if (!$this->rateLimiter->isAllowed($normalized, $ipAddress, $nowSql)) {
            $this->audit->record('AUTH_ACCOUNT_BLOCKED', null, $normalized, $ipAddress, $userAgent, $requestId, $nowSql);
            throw new ApiException(429, 'RATE_LIMITED', 'Too many authentication attempts.');
        }

        $employee = $this->employees->findByNormalizedUsername($normalized);
        if ($employee === null) {
            $this->passwords->verifyDummy($password);
            $this->denyCredentials($normalized, null, 'unknown_username', $ipAddress, $userAgent, $requestId, $nowSql);
        }
        if (!$this->passwords->verify($password, $employee->passwordHash)) {
            $this->denyCredentials($normalized, $employee->employeeUuid, 'password_mismatch', $ipAddress, $userAgent, $requestId, $nowSql);
        }
        if (!$employee->isOperationallyActive()) {
            $this->rateLimiter->recordFailure($normalized, $ipAddress, $nowSql);
            $this->audit->record('AUTH_ACCOUNT_INACTIVE', $employee->employeeUuid, $normalized, $ipAddress, $userAgent, $requestId, $nowSql, ['reason' => $employee->inactiveReason()]);
            throw new ApiException(401, 'ACCOUNT_INACTIVE', 'Account is not active.');
        }

        if ($this->passwords->needsRehash($employee->passwordHash)) {
            $this->employees->updatePasswordHash($employee->employeeUuid, $this->passwords->hash($password), $nowSql);
            $employee = $this->employees->findByUuid($employee->employeeUuid) ?? $employee;
        }

        $rawToken = $this->tokens->generate();
        $expiresAt = $now->modify("+{$this->sessionTtlSeconds} seconds");
        $sessionId = Uuid::v4();
        $this->sessions->create(
            $sessionId,
            $employee->employeeUuid,
            $this->tokens->hash($rawToken),
            $nowSql,
            $this->sqlTime($expiresAt),
            $this->tokens->metadataHash('ip', $ipAddress),
            $this->tokens->metadataHash('user-agent', $userAgent),
        );
        $this->employees->markLogin($employee->employeeUuid, $nowSql);
        $this->rateLimiter->recordSuccess($normalized, $ipAddress, $nowSql);
        $this->audit->record('AUTH_LOGIN_SUCCESS', $employee->employeeUuid, $normalized, $ipAddress, $userAgent, $requestId, $nowSql);
        $session = $this->sessions->findByTokenHash($this->tokens->hash($rawToken));
        if ($session === null) {
            throw new ApiException(500, 'INTERNAL_ERROR', 'Authentication session could not be created.');
        }
        return new AuthResult($employee, $session, $rawToken, $this->tokens->csrfToken($rawToken), $expiresAt);
    }

    public function authenticate(?string $rawToken, string $ipAddress, string $userAgent, string $requestId): AuthenticatedSession
    {
        if ($rawToken === null || $rawToken === '' || strlen($rawToken) > 256) {
            throw new ApiException(401, 'SESSION_EXPIRED', 'Authentication required.');
        }
        $tokenHash = $this->tokens->hash($rawToken);
        $session = $this->sessions->findByTokenHash($tokenHash);
        $now = $this->clock->now();
        $nowSql = $this->sqlTime($now);
        if ($session === null || !$session->isValidAt($now)) {
            if ($session !== null && $session->revokedAt === null) {
                $this->sessions->revokeByTokenHash($tokenHash, $nowSql);
                $this->audit->record('AUTH_SESSION_EXPIRED', $session->employeeUuid, null, $ipAddress, $userAgent, $requestId, $nowSql);
            }
            throw new ApiException(401, 'SESSION_EXPIRED', 'Session is invalid or expired.');
        }

        $employee = $this->employees->findByUuid($session->employeeUuid);
        if ($employee === null || !$employee->isOperationallyActive()) {
            $this->sessions->revokeAllForEmployee($session->employeeUuid, $nowSql);
            $this->audit->record('AUTH_ACCOUNT_INACTIVE', $session->employeeUuid, null, $ipAddress, $userAgent, $requestId, $nowSql, ['reason' => $employee?->inactiveReason() ?? 'employee_missing']);
            throw new ApiException(401, 'ACCOUNT_INACTIVE', 'Account is not active.');
        }

        if ($session->lastSeenAt->modify("+{$this->touchIntervalSeconds} seconds") <= $now) {
            $this->sessions->touch($session->sessionId, $nowSql);
        }
        return new AuthenticatedSession($employee, $session, $rawToken, $this->tokens->csrfToken($rawToken));
    }

    public function refresh(string $rawToken, string $ipAddress, string $userAgent, string $requestId): AuthResult
    {
        $current = $this->authenticate($rawToken, $ipAddress, $userAgent, $requestId);
        $now = $this->clock->now();
        $expiresAt = $now->modify("+{$this->sessionTtlSeconds} seconds");
        $newRawToken = $this->tokens->generate();
        $newSessionId = Uuid::v4();
        $rotated = $this->sessions->rotate(
            $current->session->sessionId,
            $this->tokens->hash($rawToken),
            $newSessionId,
            $this->tokens->hash($newRawToken),
            $this->sqlTime($now),
            $this->sqlTime($expiresAt),
            $this->tokens->metadataHash('ip', $ipAddress),
            $this->tokens->metadataHash('user-agent', $userAgent),
        );
        if (!$rotated) {
            throw new ApiException(401, 'SESSION_EXPIRED', 'Session rotation failed.');
        }
        $newSession = $this->sessions->findByTokenHash($this->tokens->hash($newRawToken));
        if ($newSession === null) {
            throw new ApiException(500, 'INTERNAL_ERROR', 'Rotated session could not be loaded.');
        }
        $this->audit->record('AUTH_SESSION_REFRESH', $current->employee->employeeUuid, null, $ipAddress, $userAgent, $requestId, $this->sqlTime($now));
        return new AuthResult($current->employee, $newSession, $newRawToken, $this->tokens->csrfToken($newRawToken), $expiresAt);
    }

    /**
     * The identity changes its own password (also the forced first-login change). Every existing session
     * is revoked and a fresh session is issued for this device only.
     */
    public function changePassword(AuthenticatedSession $current, string $currentPassword, string $newPassword, string $ipAddress, string $userAgent, string $requestId): AuthResult
    {
        $employee = $current->employee;
        $now = $this->clock->now();
        $nowSql = $this->sqlTime($now);
        // The same per-username and per-address limit as the login: a borrowed or stolen session cannot be
        // used to guess the current password without bound.
        if (!$this->rateLimiter->isAllowed($employee->usernameNormalized, $ipAddress, $nowSql)) {
            $this->audit->record('AUTH_ACCOUNT_BLOCKED', $employee->employeeUuid, $employee->usernameNormalized, $ipAddress, $userAgent, $requestId, $nowSql, ['operation' => 'password_change']);
            throw new ApiException(429, 'RATE_LIMITED', 'Too many authentication attempts.');
        }
        if (!$this->passwords->verify($currentPassword, $employee->passwordHash)) {
            $this->rateLimiter->recordFailure($employee->usernameNormalized, $ipAddress, $nowSql);
            $this->audit->record('AUTH_PASSWORD_CHANGE_DENIED', $employee->employeeUuid, null, $ipAddress, $userAgent, $requestId, $nowSql);
            throw new ApiException(400, 'CURRENT_PASSWORD_INVALID', 'The current password is not correct.');
        }
        if (!$this->passwords->meetsPolicy($newPassword, $employee->usernameNormalized) || hash_equals($currentPassword, $newPassword)) {
            throw new ApiException(400, 'PASSWORD_POLICY', 'The new password does not meet the password policy.');
        }
        if (!$this->employees->completePasswordChange($employee->employeeUuid, $this->passwords->hash($newPassword), $nowSql)) {
            throw new ApiException(500, 'INTERNAL_ERROR', 'The password could not be changed.');
        }
        $this->rateLimiter->recordSuccess($employee->usernameNormalized, $ipAddress, $nowSql);
        $revoked = $this->sessions->revokeAllForEmployee($employee->employeeUuid, $nowSql);
        $rawToken = $this->tokens->generate();
        $expiresAt = $now->modify("+{$this->sessionTtlSeconds} seconds");
        $this->sessions->create(
            Uuid::v4(),
            $employee->employeeUuid,
            $this->tokens->hash($rawToken),
            $nowSql,
            $this->sqlTime($expiresAt),
            $this->tokens->metadataHash('ip', $ipAddress),
            $this->tokens->metadataHash('user-agent', $userAgent),
        );
        $this->audit->record('AUTH_PASSWORD_CHANGED', $employee->employeeUuid, null, $ipAddress, $userAgent, $requestId, $nowSql, ['revoked_sessions' => $revoked, 'forced' => $employee->mustChangePassword]);
        $session = $this->sessions->findByTokenHash($this->tokens->hash($rawToken));
        $updated = $this->employees->findByUuid($employee->employeeUuid);
        if ($session === null || $updated === null) {
            throw new ApiException(500, 'INTERNAL_ERROR', 'Authentication session could not be created.');
        }
        return new AuthResult($updated, $session, $rawToken, $this->tokens->csrfToken($rawToken), $expiresAt);
    }

    public function logout(AuthenticatedSession $current, string $ipAddress, string $userAgent, string $requestId): void
    {
        $nowSql = $this->sqlTime($this->clock->now());
        if ($this->sessions->revokeByTokenHash($this->tokens->hash($current->rawToken), $nowSql)) {
            $this->audit->record('AUTH_LOGOUT', $current->employee->employeeUuid, null, $ipAddress, $userAgent, $requestId, $nowSql);
        }
    }

    private function denyCredentials(string $normalized, ?string $employeeUuid, string $reason, string $ipAddress, string $userAgent, string $requestId, string $nowSql): never
    {
        $this->rateLimiter->recordFailure($normalized, $ipAddress, $nowSql);
        $this->audit->record('AUTH_LOGIN_FAILURE', $employeeUuid, $normalized, $ipAddress, $userAgent, $requestId, $nowSql, ['reason' => $reason]);
        throw new ApiException(401, 'INVALID_CREDENTIALS', 'Authentication failed.');
    }

    private function sqlTime(\DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.u');
    }
}
