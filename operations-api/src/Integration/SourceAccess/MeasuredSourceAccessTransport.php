<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\SourceAccess;

use Arasya\Operations\Support\Clock;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * API 2.81.0 (P0 2026-11-25, connection and performance): every call from Operations to a shop, measured, and each shop
 * kept apart from the other.
 *
 * - Measured: one row per call in source_access_timings (host, route, milliseconds, HTTP status, outcome code, the
 *   payload's request id). Never a body, an order or a customer field. A failed write never fails the call.
 * - Kept apart (a per-host breaker for reads only): when one shop failed BREAKER_FAILURES times in the last
 *   BREAKER_SECONDS and answered nothing in that time, a READ to that shop fails at once with SOURCE_UNREACHABLE instead
 *   of waiting for its timeout, so a shop that is down does not hold every screen and worker for 8 seconds per call. The
 *   other shop is not affected (the breaker is per host). An action (anything that may write in the shop) is never
 *   stopped by the breaker: it is always sent, so its outcome stays what the shop says (never a guessed "not sent").
 *   After BREAKER_SECONDS without a call the failures age out and the next read goes to the shop again.
 */
final class MeasuredSourceAccessTransport implements SourceAccessTransport
{
    public const BREAKER_FAILURES = 4;
    public const BREAKER_SECONDS = 60;
    public const KEEP_DAYS = 14;
    /** Read-only routes: the only ones the breaker may stop. */
    public const READ_ROUTES = ['finance', 'invoice', 'detail', 'catalog', 'product', 'edit-status', 'finance-status', 'invoice-status', 'invoice-payment', 'transfer-exceptions', 'operation-status'];

    public function __construct(private SourceAccessTransport $inner, private ?PDO $pdo, private Clock $clock, private bool $breaker = true)
    {
    }

    public function post(string $baseUrl, string $path, array $headers, string $body): array
    {
        $host = strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?? ''));
        // The last path segment names the route (the transport itself checks the whole path).
        $route = preg_match('#/confirmare/([a-z-]{1,40})$#D', $path, $match) === 1 ? $match[1] : 'other';
        $requestId = null;
        if (preg_match('/"requestId":"([A-Za-z0-9._:-]{1,100})"/', $body, $found) === 1) {
            $requestId = $found[1];
        }
        if (in_array($route, self::READ_ROUTES, true) && $this->open($host)) {
            $this->record($host, $route, 0, null, 'breaker_open', $requestId);
            throw new SourceAccessException('SOURCE_UNREACHABLE');
        }
        $started = hrtime(true);
        try {
            $response = $this->inner->post($baseUrl, $path, $headers, $body);
        } catch (SourceAccessException $e) {
            $this->record($host, $route, self::elapsed($started), null, strtolower($e->codeName), $requestId);
            throw $e;
        }
        $status = (int) $response['status'];
        // A shop answer the shop did not sign (a host error page) is counted as a failure of the shop.
        $signed = ($response['headers'][strtolower(SourceAccessProtocol::HEADER_SIGNATURE)] ?? '') !== '';
        $this->record($host, $route, self::elapsed($started), $status, $signed ? ($status >= 500 ? 'shop_error' : 'ok') : ($status >= 500 || $status === 0 ? 'host_error' : 'unsigned'), $requestId);
        return $response;
    }

    /** Whether the breaker of this host is open: recent failures and no answer in the window. */
    private function open(string $host): bool
    {
        if (!$this->breaker || $this->pdo === null || $host === '') {
            return false;
        }
        try {
            $since = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->modify('-' . self::BREAKER_SECONDS . ' seconds')->format('Y-m-d H:i:s.u');
            $statement = $this->pdo->prepare("SELECT SUM(outcome IN ('ok', 'shop_error', 'unsigned')) AS answered,
                    SUM(outcome IN ('source_unreachable', 'host_error', 'source_response_too_large')) AS failed
                FROM source_access_timings WHERE shop_host = ? AND called_at >= ?");
            $statement->execute([$host, $since]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return is_array($row) && (int) ($row['answered'] ?? 0) === 0 && (int) ($row['failed'] ?? 0) >= self::BREAKER_FAILURES;
        } catch (Throwable) {
            return false;
        }
    }

    private function record(string $host, string $route, int $ms, ?int $status, string $outcome, ?string $requestId): void
    {
        if ($this->pdo === null) {
            return;
        }
        try {
            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
            $this->pdo->prepare('INSERT INTO source_access_timings (called_at, shop_host, route, duration_ms, http_status, outcome, request_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$now->format('Y-m-d H:i:s.u'), substr($host, 0, 190), substr($route, 0, 60), max(0, $ms), $status, substr(preg_replace('/[^a-z_]/', '', $outcome) ?? 'unknown', 0, 40), $requestId]);
            if (random_int(1, 500) === 1) {
                $this->pdo->prepare('DELETE FROM source_access_timings WHERE called_at < ? LIMIT 5000')->execute([$now->modify('-' . self::KEEP_DAYS . ' days')->format('Y-m-d H:i:s.u')]);
            }
        } catch (Throwable) {
            // Measuring never fails a call (an older schema without the table, a lost connection).
        }
    }

    private static function elapsed(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1e6);
    }
}
