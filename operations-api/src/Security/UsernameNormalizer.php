<?php

declare(strict_types=1);

namespace Arasya\Operations\Security;

final class UsernameNormalizer
{
    public function normalize(string $username): string
    {
        return mb_strtolower(trim($username), 'UTF-8');
    }
}

