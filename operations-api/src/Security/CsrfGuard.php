<?php

declare(strict_types=1);

namespace Arasya\Operations\Security;

use Arasya\Operations\Http\ApiException;

final readonly class CsrfGuard
{
    public function __construct(private SessionTokenManager $tokens)
    {
    }

    public function requireValid(string $rawSessionToken, ?string $providedToken): void
    {
        $expected = $this->tokens->csrfToken($rawSessionToken);
        if ($providedToken === null || !hash_equals($expected, $providedToken)) {
            throw new ApiException(403, 'CSRF_INVALID', 'CSRF validation failed.');
        }
    }
}

