<?php

declare(strict_types=1);

namespace Arasya\Operations\Http;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $safeMessage,
    ) {
        parent::__construct($safeMessage);
    }
}

