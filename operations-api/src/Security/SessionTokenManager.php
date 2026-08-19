<?php

declare(strict_types=1);

namespace Arasya\Operations\Security;

final readonly class SessionTokenManager
{
    public function __construct(private string $appSecret)
    {
    }

    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken, true);
    }

    public function csrfToken(string $rawToken): string
    {
        return hash_hmac('sha256', 'csrf|' . $rawToken, $this->appSecret);
    }

    public function metadataHash(string $scope, string $value): string
    {
        return hash_hmac('sha256', "metadata|{$scope}|{$value}", $this->appSecret, true);
    }
}
