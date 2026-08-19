<?php

declare(strict_types=1);

namespace Arasya\Operations\Http;

use JsonException;

final readonly class Request
{
    /** @param array<string, string> $headers @param array<string, string> $cookies */
    public function __construct(
        public string $method,
        public string $path,
        public array $headers,
        public array $cookies,
        public string $body,
        public string $ipAddress,
        public string $userAgent,
        public string $requestId,
        public array $query = [],
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function cookie(string $name): ?string
    {
        return isset($this->cookies[$name]) ? rawurldecode($this->cookies[$name]) : null;
    }

    public function query(string $name, ?string $default = null): ?string
    {
        return isset($this->query[$name]) && is_scalar($this->query[$name]) ? (string) $this->query[$name] : $default;
    }

    /** @return array<string, mixed> */
    public function json(int $maxBytes = 8192): array
    {
        $contentType = strtolower(trim(explode(';', $this->header('content-type') ?? '')[0]));
        if ($contentType !== 'application/json') {
            throw new ApiException(415, 'UNSUPPORTED_MEDIA_TYPE', 'Content-Type must be application/json.');
        }
        if (strlen($this->body) > $maxBytes) {
            throw new ApiException(413, 'REQUEST_TOO_LARGE', 'Request body is too large.');
        }
        try {
            $decoded = json_decode($this->body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException(400, 'MALFORMED_JSON', 'Malformed JSON request.');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'JSON body must be an object.');
        }
        return $decoded;
    }
}

