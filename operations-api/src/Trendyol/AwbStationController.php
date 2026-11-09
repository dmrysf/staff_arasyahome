<?php

declare(strict_types=1);

namespace Arasya\Operations\Trendyol;

use Arasya\Operations\Auth\AuthenticatedSession;
use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Http\Response;
use Arasya\Operations\Iam\ApplicationAccess;
use Arasya\Operations\Security\CsrfGuard;

/**
 * HTTP surface of the Trendyol AWB print station (API 2.65.0). Session cookie, Staff application access, CSRF on the
 * one mutation, strict bodies, `Cache-Control: private, no-store` on every answer.
 *
 *   GET  /trendyol/awb/queue?q=&cursor=&limit=              Trendyol orders at stage 13 (newest arrival first)
 *   GET  /trendyol/awb/resolve?code=                         exact QR / order number / package id search
 *   GET  /trendyol/awb/orders/{orderId}/label?parcel=N       the official Trendyol label PDF (records the retrieval only)
 *   POST /trendyol/awb/orders/{orderId}/print-attempts       {attemptId, retrievalId}: the browser opened its print flow
 *   GET  /trendyol/awb/orders/{orderId}/history              the minimal print history
 *
 * The label is a GET because it changes nothing anywhere except the retrieval record; it never counts as a print.
 */
final readonly class AwbStationController
{
    public function __construct(
        private AwbStation $station,
        private AuthenticationService $auth,
        private CsrfGuard $csrf,
        private Config $config,
        private RequestContext $context,
    ) {
    }

    public function handle(Request $request): Response
    {
        $path = substr($request->path, strlen('/trendyol/awb'));
        if ($path === '/queue') {
            $this->method($request, 'GET');
            return $this->json($this->station->queue($this->session($request)->employee, $request->query('q'), $request->query('cursor'), $request->query('limit'), $request->query('stage')));
        }
        if ($path === '/resolve') {
            $this->method($request, 'GET');
            return $this->json($this->station->resolve($this->session($request)->employee, $request->query('code') ?? ''));
        }
        if (preg_match('#^/orders/([0-9a-fA-F-]{36})/(label|print-attempts|history)$#D', $path, $m) === 1) {
            if ($m[2] === 'label') {
                $this->method($request, 'GET');
                $label = $this->station->label($this->session($request)->employee, $m[1], $request->query('parcel'), $request->requestId);
                return Response::file($label['pdf'], [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="awb-trendyol.pdf"',
                    'Cache-Control' => 'private, no-store',
                    'X-Content-Type-Options' => 'nosniff',
                    'X-Arasya-Label-Retrieval' => $label['retrievalId'],
                    'X-Arasya-Label-Parcel' => (string) $label['parcel'],
                    'X-Arasya-Label-Parcels' => (string) $label['parcelCount'],
                ]);
            }
            if ($m[2] === 'history') {
                $this->method($request, 'GET');
                return $this->json($this->station->history($this->session($request)->employee, $m[1]));
            }
            $this->method($request, 'POST');
            $session = $this->session($request);
            $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
            $input = $request->json(1_024);
            if (array_keys($input) !== ['attemptId', 'retrievalId'] && array_keys($input) !== ['retrievalId', 'attemptId']) {
                throw new ApiException(400, 'INVALID_REQUEST', 'Request fields are invalid.');
            }
            return $this->json($this->station->recordPrint($session->employee, $m[1], $input, $request->requestId), 201);
        }
        throw new ApiException(404, 'NOT_FOUND', 'API route was not found.');
    }

    private function method(Request $request, string $method): void
    {
        if ($request->method !== $method) {
            throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
        }
    }

    private function session(Request $request): AuthenticatedSession
    {
        $session = $this->auth->authenticate($request->cookie($this->config->cookieName()) ?? '', $request->ipAddress, $request->userAgent, $request->requestId);
        $this->context->authenticatedAs($session->employee->employeeUuid);
        if (!$session->employee->hasApplication(ApplicationAccess::STAFF)) {
            throw new ApiException(403, 'APPLICATION_ACCESS_DENIED', 'The account has no access to this application.');
        }
        return $session;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): Response
    {
        return Response::json($payload, $status, ['Cache-Control' => 'private, no-store']);
    }
}
