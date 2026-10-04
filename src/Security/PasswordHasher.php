<?php

declare(strict_types=1);

namespace Arasya\Operations\Security;

use RuntimeException;

final class PasswordHasher
{
    private const ARGON2ID_DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$VnJuSVpnQkxMYUEyMmZtbw$VnJjJax8GPK62zIoEdEYMa70bop5yw1p1UY68iKWS90';
    private const BCRYPT_DUMMY_HASH = '$2y$12$Dj/GKxIClhOgGZsU8Ckv7uFdYj6clrpks7sNK8xLurMfSZsOECltS';

    public function hash(string $password): string
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algorithm);
        if (!is_string($hash)) {
            throw new RuntimeException('Password hashing failed.');
        }
        return $hash;
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function verifyDummy(string $password): void
    {
        password_verify($password, defined('PASSWORD_ARGON2ID') ? self::ARGON2ID_DUMMY_HASH : self::BCRYPT_DUMMY_HASH);
    }

    public function needsRehash(string $hash): bool
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        return password_needs_rehash($hash, $algorithm);
    }

    /** Policy for passwords people choose: 12–1024 bytes, not trivially equal to the username. */
    public function meetsPolicy(string $password, string $usernameNormalized = ''): bool
    {
        $length = strlen($password);
        if ($length < 12 || $length > 1024 || trim($password) === '') {
            return false;
        }
        return $usernameNormalized === '' || !str_contains(mb_strtolower($password), $usernameNormalized);
    }

    public function assertPolicy(string $password): void
    {
        $length = strlen($password);
        if ($length < 10 || $length > 1024) {
            throw new RuntimeException('Password must contain between 10 and 1024 bytes.');
        }
    }
}
