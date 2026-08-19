<?php

declare(strict_types=1);

namespace Arasya\Operations\Security;

use Arasya\Operations\Config\Config;
use DateTimeImmutable;

final readonly class CookiePolicy
{
    public function __construct(private Config $config)
    {
    }

    public function session(string $rawToken, DateTimeImmutable $expiresAt): string
    {
        return sprintf(
            '%s=%s; Expires=%s; Max-Age=%d; Path=/;%s HttpOnly; SameSite=Lax',
            $this->config->cookieName(),
            rawurlencode($rawToken),
            $expiresAt->format('D, d M Y H:i:s') . ' GMT',
            max(0, $expiresAt->getTimestamp() - time()),
            $this->config->isProduction() ? ' Secure;' : '',
        );
    }

    public function clear(): string
    {
        return sprintf(
            '%s=; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0; Path=/;%s HttpOnly; SameSite=Lax',
            $this->config->cookieName(),
            $this->config->isProduction() ? ' Secure;' : '',
        );
    }
}

