<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

use Closure;

/**
 * DNS fallback for the Trendyol hosts (API 2.76.1, incident 2026-11-19).
 *
 * The production host may use only the hosting provider's recursive resolvers, and outbound DNS (UDP 53) is blocked.
 * From 2026-11-17 those resolvers answered SERVFAIL for trendyol.com and dsmcdn.com for hours at a time, while
 * Trendyol itself answered normally. PHP then failed with "getaddrinfo ... failed", and the AWB station and the intake
 * stopped.
 *
 * route() changes nothing while the system resolver answers. Only when the system resolver cannot resolve one of HOSTS
 * does it ask a DNS-over-HTTPS service (DOH_ENDPOINTS, by IP address, TLS verified against the service's own name) for
 * the host's public IPv4 address. The caller then connects to that address and still verifies Trendyol's certificate
 * against the original host name (ssl peer_name and the Host header). A wrong or hijacked address therefore fails the
 * TLS check; it can never receive a request. No other host is ever resolved this way, nothing is written, and no
 * credential, path or query ever reaches a DNS service: only the host name.
 */
final class TrendyolHostResolver
{
    /** The only hosts that may use the fallback: the Trendyol API gateway and the two label document hosts. */
    public const HOSTS = ['apigw.trendyol.com', 's3.trendyol.com', 'cdn.dsmcdn.com'];
    /** DNS-over-HTTPS services, tried in order: [endpoint by IP address, name its certificate is checked against]. */
    public const DOH_ENDPOINTS = [['https://1.1.1.1/dns-query', 'cloudflare-dns.com'], ['https://8.8.8.8/resolve', 'dns.google']];
    public const DOH_TIMEOUT_SECONDS = 5;

    /** @var array<string, string> host => IPv4 address found through the fallback in this process */
    private array $resolved = [];

    /**
     * @param Closure(string): bool $systemResolves whether the system resolver answers for the host
     * @param Closure(string): list<string> $lookup public IPv4 addresses of the host through DNS-over-HTTPS
     */
    public function __construct(private readonly Closure $systemResolves, private readonly Closure $lookup)
    {
    }

    /** The production resolver: the system resolver first, then DOH_ENDPOINTS. */
    public static function live(): self
    {
        return new self(static fn (string $host): bool => gethostbynamel($host) !== false, self::dohLookup(...));
    }

    /**
     * How to reach $url: null to connect normally (the system resolver answers, or the host is not one of HOSTS, or the
     * fallback found nothing), otherwise the same URL with the host replaced by an IPv4 address and the original host,
     * which the caller must send as the Host header and verify the certificate against.
     *
     * @return array{url: string, host: string}|null
     */
    public function route(string $url): ?array
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || !in_array($host, self::HOSTS, true)) {
            return null;
        }
        if (!isset($this->resolved[$host])) {
            if (($this->systemResolves)($host)) {
                return null;
            }
            $address = (($this->lookup)($host))[0] ?? null;
            if (!is_string($address) || !self::isPublicIpv4($address)) {
                error_log("TRENDYOL_DNS_FALLBACK host={$host} result=unresolved");
                return null;
            }
            error_log("TRENDYOL_DNS_FALLBACK host={$host} result=resolved");
            $this->resolved[$host] = $address;
        }
        $pinned = preg_replace('#^https://[^/?:]+#i', 'https://' . $this->resolved[$host], $url, 1);
        return is_string($pinned) ? ['url' => $pinned, 'host' => $host] : null;
    }

    /**
     * The public IPv4 addresses of one of HOSTS from the first DOH_ENDPOINTS service that answers. GET only, TLS verified,
     * no redirect, bounded size and time; nothing but the host name is sent.
     *
     * @return list<string>
     */
    public static function dohLookup(string $host): array
    {
        if (!in_array($host, self::HOSTS, true)) {
            return [];
        }
        foreach (self::DOH_ENDPOINTS as [$endpoint, $serviceName]) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "Accept: application/dns-json\r\nHost: {$serviceName}\r\nUser-Agent: Arasya-Operations-DNS",
                    'timeout' => self::DOH_TIMEOUT_SECONDS,
                    'ignore_errors' => true,
                    'follow_location' => 0,
                    'max_redirects' => 0,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $serviceName],
            ]);
            $body = @file_get_contents($endpoint . '?' . http_build_query(['name' => $host, 'type' => 'A']), false, $context, 0, 64 * 1024);
            $addresses = is_string($body) ? self::answers($body, $host) : [];
            if ($addresses !== []) {
                return $addresses;
            }
        }
        return [];
    }

    /**
     * The public IPv4 addresses in a DNS JSON answer (RFC 8484 JSON form used by both services) for $host: status
     * NOERROR, A records only, after the CNAME chain. Anything else gives an empty list.
     *
     * @return list<string>
     */
    public static function answers(string $json, string $host): array
    {
        $decoded = json_decode($json, true, 8);
        if (!is_array($decoded) || ($decoded['Status'] ?? null) !== 0 || !is_array($decoded['Answer'] ?? null)) {
            return [];
        }
        $question = $decoded['Question'][0]['name'] ?? null;
        if (!is_string($question) || strtolower(rtrim($question, '.')) !== $host) {
            return [];
        }
        $addresses = [];
        foreach ($decoded['Answer'] as $record) {
            if (is_array($record) && ($record['type'] ?? null) === 1 && is_string($record['data'] ?? null) && self::isPublicIpv4($record['data'])) {
                $addresses[] = $record['data'];
            }
        }
        return array_values(array_unique($addresses));
    }

    /** What failed in a stream request, from PHP's last error, for the log: no URL, path or query is kept. */
    public static function failureKind(?array $lastError): string
    {
        $message = strtolower((string) ($lastError['message'] ?? ''));
        return match (true) {
            str_contains($message, 'getaddrinfo') || str_contains($message, 'name or service not known') => 'dns',
            str_contains($message, 'timed out') => 'timeout',
            str_contains($message, 'ssl') || str_contains($message, 'certificate') || str_contains($message, 'crypto') => 'tls',
            str_contains($message, 'refused') || str_contains($message, 'unreachable') => 'connect',
            default => 'other',
        };
    }

    private static function isPublicIpv4(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
