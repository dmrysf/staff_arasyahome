<?php

declare(strict_types=1);

namespace Arasya\Operations\Http;

use Arasya\Operations\Auth\AuthResult;
use Arasya\Operations\Auth\AuthenticatedSession;
use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeSerializer;
use Arasya\Operations\Security\CookiePolicy;
use Arasya\Operations\Security\CsrfGuard;

final readonly class AuthController
{
    public function __construct(
        private AuthenticationService $auth,
        private CsrfGuard $csrf,
        private CookiePolicy $cookies,
        private Config $config,
        private AuthorizationService $authorization,
    ) {
    }

    public function login(Request $request): Response
    {
        $input = $request->json();
        $this->requireKeys($input, ['username', 'password']);
        if (!is_string($input['username']) || !is_string($input['password'])) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Username and password must be strings.');
        }
        $result = $this->auth->login($input['username'], $input['password'], $request->ipAddress, $request->userAgent, $request->requestId);
        return Response::json($this->payload($result), 200, ['Set-Cookie' => $this->cookies->session($result->rawToken, $result->expiresAt)]);
    }

    public function session(Request $request): Response
    {
        $session = $this->current($request);
        return Response::json($this->sessionPayload($session));
    }

    public function refresh(Request $request): Response
    {
        $rawToken = $this->rawToken($request);
        $this->csrf->requireValid($rawToken, $request->header('x-csrf-token'));
        $result = $this->auth->refresh($rawToken, $request->ipAddress, $request->userAgent, $request->requestId);
        return Response::json($this->payload($result), 200, ['Set-Cookie' => $this->cookies->session($result->rawToken, $result->expiresAt)]);
    }

    public function logout(Request $request): Response
    {
        $rawToken = $this->rawToken($request);
        $this->csrf->requireValid($rawToken, $request->header('x-csrf-token'));
        $this->auth->logout($rawToken, $request->ipAddress, $request->userAgent, $request->requestId);
        return Response::json(['ok' => true], 200, ['Set-Cookie' => $this->cookies->clear()]);
    }

    public function employee(Request $request): Response
    {
        $employee = $this->current($request)->employee;
        $this->authorization->require($employee, 'profile.view_self');
        return Response::json(EmployeeSerializer::safe($employee));
    }

    private function current(Request $request): AuthenticatedSession
    {
        return $this->auth->authenticate($this->rawToken($request), $request->ipAddress, $request->userAgent, $request->requestId);
    }

    private function rawToken(Request $request): string
    {
        return $request->cookie($this->config->cookieName()) ?? '';
    }

    /** @return array<string, mixed> */
    private function payload(AuthResult $result): array
    {
        return [
            'employee' => EmployeeSerializer::safe($result->employee),
            'expiresAt' => $result->expiresAt->format(DATE_ATOM),
            'csrfToken' => $result->csrfToken,
        ];
    }

    /** @return array<string, mixed> */
    private function sessionPayload(AuthenticatedSession $session): array
    {
        return [
            'employee' => EmployeeSerializer::safe($session->employee),
            'expiresAt' => $session->session->expiresAt->format(DATE_ATOM),
            'csrfToken' => $session->csrfToken,
        ];
    }

    /** @param array<string, mixed> $input @param list<string> $keys */
    private function requireKeys(array $input, array $keys): void
    {
        $actual = array_keys($input);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Request fields are invalid.');
        }
    }
}
