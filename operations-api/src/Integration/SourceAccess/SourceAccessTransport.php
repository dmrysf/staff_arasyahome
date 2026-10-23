<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\SourceAccess;

interface SourceAccessTransport
{
    /**
     * One POST to the configured shop. Implementations never follow a redirect and fail with SourceAccessException.
     *
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string} lower-case header names
     */
    public function post(string $baseUrl, string $path, array $headers, string $body): array;
}
