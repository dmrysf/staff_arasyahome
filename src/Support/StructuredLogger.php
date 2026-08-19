<?php

declare(strict_types=1);

namespace Arasya\Operations\Support;

use JsonException;

final class StructuredLogger
{
    /** @param array<string, scalar|null> $context */
    public function log(string $level, string $event, string $requestId, array $context = []): void
    {
        foreach (array_keys($context) as $key) {
            if (preg_match('/password|token|csrf|authorization|cookie|secret/i', $key) === 1) {
                unset($context[$key]);
            }
        }

        try {
            error_log(json_encode([
                'timestamp' => gmdate(DATE_ATOM),
                'level' => $level,
                'request_id' => $requestId,
                'event' => $event,
                ...$context,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (JsonException) {
            error_log('{"level":"error","event":"structured_log_encoding_failed"}');
        }
    }
}

