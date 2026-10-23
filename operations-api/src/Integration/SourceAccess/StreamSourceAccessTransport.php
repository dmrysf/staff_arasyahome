<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\SourceAccess;

use Closure;

/**
 * The only outbound HTTP path to a shop (API 2.40.0), dependency-free like the Trendyol transport, and allowed by
 * tests/inbound-only-guard.php only in this file and only as POST to the source access routes.
 *
 * SSRF protection, in this order:
 * - the base URL comes from configuration only (SourceAccessRegistry: the allowlist) and is checked again here;
 * - the host is resolved once; EVERY address must be public (no private, loopback, link-local, reserved or mapped
 *   range), except an explicit loopback test shop outside production;
 * - the connection goes to that resolved address (no second lookup, so a DNS rebinding cannot redirect it) while TLS
 *   verifies the certificate for the configured host name (peer_name, SNI);
 * - no redirect is ever followed; HTTP/1.0 (no chunked framing); bounded connect/read time and response size.
 */
final readonly class StreamSourceAccessTransport implements SourceAccessTransport
{
    public const MAX_RESPONSE_BYTES = 1_048_576;

    /** @param Closure(string): list<string>|null $resolver host => addresses (tests) */
    public function __construct(
        private bool $allowLoopback = false,
        private int $timeoutSeconds = 8,
        private ?Closure $resolver = null,
    ) {
    }

    public function post(string $baseUrl, string $path, array $headers, string $body): array
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '') || preg_match('#^/wp-json/yd-arasya/v1/confirmare/[a-z-]{1,40}$#D', $path) !== 1) {
            throw new SourceAccessException('SOURCE_ACCESS_NOT_CONFIGURED');
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if ($scheme !== 'https' && !($scheme === 'http' && $this->allowLoopback)) {
            throw new SourceAccessException('SOURCE_ACCESS_NOT_CONFIGURED');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $address = $this->address($host);
        if ($scheme === 'http' && !self::isLoopback($address)) {
            // Plain HTTP only ever reaches a loopback test shop.
            throw new SourceAccessException('SOURCE_ADDRESS_REFUSED');
        }
        $authority = str_contains($address, ':') ? "[{$address}]" : $address;
        $lines = ['Host: ' . $host . (isset($parts['port']) ? ":{$port}" : ''), 'Content-Type: application/json', 'Accept: application/json', 'Connection: close', 'User-Agent: ArasyaOperations-SourceAccess/1'];
        foreach ($headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]{1,64}$/D', $name) !== 1 || preg_match('/[\r\n]/', $value) === 1) {
                throw new SourceAccessException('SOURCE_ACCESS_NOT_CONFIGURED');
            }
            $lines[] = "{$name}: {$value}";
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $lines),
                'content' => $body,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'protocol_version' => 1.0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ]);
        $previous = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string) $this->timeoutSeconds);
        try {
            $raw = @file_get_contents("{$scheme}://{$authority}:{$port}{$path}", false, $context, 0, self::MAX_RESPONSE_BYTES + 1);
            $responseHeaders = $http_response_header ?? [];
        } finally {
            ini_set('default_socket_timeout', $previous === false ? '60' : $previous);
        }
        if ($raw === false || $responseHeaders === []) {
            throw new SourceAccessException('SOURCE_UNREACHABLE');
        }
        if (strlen($raw) > self::MAX_RESPONSE_BYTES) {
            throw new SourceAccessException('SOURCE_RESPONSE_TOO_LARGE');
        }
        $status = 0;
        $parsed = [];
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
                $parsed = [];
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $parsed[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
            }
        }
        return ['status' => $status, 'headers' => $parsed, 'body' => $raw];
    }

    /** The one address to connect to: every resolved address must be allowed. */
    private function address(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } elseif ($host === 'localhost') {
            $addresses = ['127.0.0.1'];
        } else {
            $addresses = $this->resolver !== null ? ($this->resolver)($host) : self::resolve($host);
        }
        if ($addresses === []) {
            throw new SourceAccessException('SOURCE_UNREACHABLE');
        }
        foreach ($addresses as $address) {
            if (!$this->allowed($address)) {
                throw new SourceAccessException('SOURCE_ADDRESS_REFUSED');
            }
        }
        return $addresses[0];
    }

    private function allowed(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (self::isLoopback($address)) {
            return $this->allowLoopback;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $lower = strtolower($address);
        // Ranges PHP's flags do not cover: carrier-grade NAT, IPv6 unique-local / link-local / mapped / documentation.
        return !(self::inIpv4($address, '100.64.0.0', 10) || self::inIpv4($address, '192.0.0.0', 24) || self::inIpv4($address, '198.18.0.0', 15)
            || str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd') || str_starts_with($lower, 'fe80:') || str_starts_with($lower, '::ffff:')
            || str_starts_with($lower, '2001:db8:') || str_starts_with($lower, '64:ff9b:'));
    }

    private static function isLoopback(string $address): bool
    {
        return $address === '::1' || str_starts_with($address, '127.');
    }

    private static function inIpv4(string $address, string $network, int $bits): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $mask = -1 << (32 - $bits);
        return (ip2long($address) & $mask) === (ip2long($network) & $mask);
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }
        return array_values(array_unique($addresses));
    }
}
