<?php

declare(strict_types=1);

namespace Arasya\Operations\Http;

use RuntimeException;

final class ApiException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details Safe, structured context for the client (for example which fields failed
     *                                      validation). Never raw input values, secrets or internal state.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $safeMessage,
        public readonly array $details = [],
    ) {
        parent::__construct($safeMessage);
    }
}
