<?php

declare(strict_types=1);

namespace Arasya\Operations\Iam;

/**
 * Receives one-time temporary credentials for offline, individual delivery. Implementations must never
 * log, print or audit the password.
 */
interface CredentialSink
{
    /** @param array{name: string, username: string, temporaryPassword: string, applications: list<string>, reason: string} $credential */
    public function write(array $credential): void;

    /** Where the credentials are kept, for the operator (never the credentials themselves). */
    public function location(): string;

    public function count(): int;
}
