<?php
declare(strict_types=1);
namespace Arasya\Operations\Cutting;
use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Http\Response;
use Arasya\Operations\Quality\LiveEvents;
use Arasya\Operations\Security\CsrfGuard;
use Arasya\Operations\Security\PdoApiRateLimiter;

final readonly class CuttingController
{
    public function __construct(private CuttingService $cutting, private DisplayDevices $devices, private BoardSnapshot $board, private LiveEvents $live,
      private AuthenticationService $auth, private CsrfGuard $csrf, private Config $config, private RequestContext $context, private PdoApiRateLimiter $limiter) {}
    public function handle(Request $request): Response
    {
        if (str_starts_with($request->path, '/display/cutting/')) return $this->display($request);
        $session = $this->auth->authenticate($request->cookie($this->config->cookieName()) ?? '', $request->ipAddress, $request->userAgent, $request->requestId);
        $actor = $session->employee;
        $this->context->authenticatedAs($actor->employeeUuid);
        if ($request->method !== 'GET') $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
        $key = $request->header('idempotency-key') ?? '';
        $management = str_starts_with($request->path, '/management/');
        $base = $management ? '/management/cutting' : '/cutting';
        $path = substr($request->path, strlen($base));
        if ($request->method === 'GET') {
            if (!$management && $path === '/pool') return $this->json($this->cutting->pool($actor));
            if (!$management && $path === '/targets') return $this->json($this->cutting->candidates($actor));
            if ($path === '/transfers') return $this->json($this->cutting->list($actor, $management, $request->query('view') ?? 'pending'));
            if (preg_match('#^/transfers/([0-9a-f-]{36})$#D', $path, $m)) return $this->json($this->cutting->detail($actor, $m[1], $management));
            if ($management && $path === '/devices') return $this->json($this->devices->list($actor));
        }
        if ($request->method === 'POST') {
            if (!$management && preg_match('#^/orders/([^/]{1,600})/transfers$#D', $path, $m)) return $this->json($this->cutting->request($actor, rawurldecode($m[1]), $this->body($request, ['expectedVersion','targetId','reasonKey','comment'], ['expectedVersion','targetId','reasonKey']), $key, $request->requestId), 201);
            if (preg_match('#^/transfers/([0-9a-f-]{36})/(decision|cancel|accept|verify)$#D', $path, $m)) {
                if ($management !== in_array($m[2], ['decision','cancel'], true)) throw new ApiException(404, 'NOT_FOUND', 'Route not found.');
                $allowed = match($m[2]) { 'decision' => ['expectedVersion','decision','comment'], 'cancel' => ['expectedVersion','comment'], 'accept' => ['expectedVersion','confirmed'], default => ['expectedVersion','qrToken','confirmedMultiple','ownedCount'] };
                return $this->json($this->cutting->change($actor, $m[1], $m[2], $this->body($request, $allowed, ['expectedVersion']), $key, $request->requestId));
            }
            if ($management && $path === '/devices') return $this->json($this->devices->admin($actor, 'create', null, $this->body($request, ['name'], ['name']), $key, $request->requestId), 201);
            if ($management && preg_match('#^/devices/([0-9a-f-]{36})/(revoke|re-pair)$#D', $path, $m)) {
                $body = $this->body($request, ['confirmed'], ['confirmed']);
                if ($body['confirmed'] !== true) throw new ApiException(422, 'CONFIRMATION_REQUIRED', 'Confirmă acțiunea.');
                return $this->json($this->devices->admin($actor, $m[2] === 're-pair' ? 'repair' : 'revoke', $m[1], $body, $key, $request->requestId));
            }
        }
        if ($request->method === 'PUT' && $management && $path === '/thresholds') return $this->json($this->devices->admin($actor, 'thresholds', null, $this->body($request, ['expectedVersion','thresholds'], ['expectedVersion','thresholds']), $key, $request->requestId));
        throw new ApiException(404, 'NOT_FOUND', 'Route not found.');
    }
    public function display(Request $request): Response
    {
        if ($request->method === 'POST' && $request->path === '/display/cutting/pair') {
            if (!$this->limiter->hit('cutting-pair', $request->ipAddress, 10, 60)) throw new ApiException(429, 'RATE_LIMITED', 'Prea multe încercări. Așteaptă un minut.');
            $body = $this->body($request, ['code'], ['code']);
            return $this->json(['paired' => true])->withHeaders(['Set-Cookie' => $this->devices->pair($body['code'], $request->requestId)]);
        }
        try { $status = $this->devices->authenticate($request->cookie($this->devices->cookieName()) ?? ''); }
        catch (ApiException $e) {
            if ($e->errorCode !== 'DISPLAY_SESSION_INVALID') throw $e;
            return $this->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], 401)->withHeaders(['Set-Cookie' => $this->devices->cookie('', 0)]);
        }
        if ($request->method === 'GET' && $request->path === '/display/cutting/status') return $this->json($status);
        if ($request->method === 'GET' && $request->path === '/display/cutting/snapshot') return $this->json($this->board->snapshot());
        if ($request->method === 'GET' && $request->path === '/live/events' && $request->query('scope') === 'cutting-display') {
            $after = $request->query('after');
            if ($after !== null && $after !== '' && (!ctype_digit($after) || strlen($after) > 18)) throw new ApiException(400, 'INVALID_CURSOR', 'Invalid cursor.');
            $frames = "retry: 3000\n\n";
            if ($after === null || $after === '') $frames .= $this->frame(null, 'ready', ['cursor' => $this->live->latestSequence(), 'stateKey' => $this->board->stateKey()]);
            else {
                $cursor = (int) $after;
                foreach ($this->live->displayAfter($cursor) as $event) { $frames .= $this->frame($event['seq'], $event['type'], []); $cursor = $event['seq']; }
                $frames .= $this->frame(null, 'cursor', ['cursor' => $cursor, 'stateKey' => $this->board->stateKey()]);
            }
            return Response::file($frames, ['Content-Type' => 'text/event-stream; charset=utf-8', 'Cache-Control' => 'private, no-store', 'X-Accel-Buffering' => 'no']);
        }
        throw new ApiException(404, 'NOT_FOUND', 'Display route not found.');
    }
    private function body(Request $request, array $allowed, array $required): array
    {
        $body = $request->json(16384);
        if (array_diff(array_keys($body), $allowed) !== [] || array_diff($required, array_keys($body)) !== []) throw new ApiException(400, 'INVALID_REQUEST', 'Request fields are invalid.');
        return $body;
    }
    private function json(array $data, int $status = 200): Response { return Response::json($data, $status, ['Cache-Control' => 'private, no-store']); }
    private function frame(?int $id, string $type, array $payload): string { return ($id === null ? '' : "id: {$id}\n") . "event: {$type}\ndata: " . json_encode($payload === [] ? (object) [] : $payload, JSON_THROW_ON_ERROR) . "\n\n"; }
}
