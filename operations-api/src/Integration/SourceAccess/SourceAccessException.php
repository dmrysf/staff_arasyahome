<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\SourceAccess;

use RuntimeException;

/**
 * A source access failure as a fixed code (the message is the code). Never carries a URL, a header, a body, an address
 * or any upstream text, so it can be logged and shown safely.
 *
 * Codes: SOURCE_ACCESS_NOT_CONFIGURED, SOURCE_ADDRESS_REFUSED, SOURCE_UNREACHABLE, SOURCE_RESPONSE_TOO_LARGE,
 * SOURCE_RESPONSE_UNTRUSTED, SOURCE_RESPONSE_INVALID, SOURCE_ACCESS_DENIED, SOURCE_RATE_LIMITED, SOURCE_DISABLED,
 * SOURCE_ORDER_NOT_FOUND, SOURCE_ORDER_NOT_ELIGIBLE, SOURCE_ERROR, plus the edit codes of Phase 4B.
 */
final class SourceAccessException extends RuntimeException
{
    public function __construct(public readonly string $codeName, public readonly ?string $sourceCode = null)
    {
        parent::__construct($codeName);
    }
}
