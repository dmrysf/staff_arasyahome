<?php

declare(strict_types=1);

namespace Arasya\Operations\Bootstrap;

use RuntimeException;

final class RuntimeLocator
{
    public static function locate(?string $override, string $nestedRoot, ?string $home, string $publicRoot): string
    {
        $override = trim($override ?? '');
        if ($override !== '') {
            return self::validatedRoot($override);
        }

        $nested = self::tryRoot($nestedRoot);
        if ($nested !== null) {
            return $nested;
        }

        $home = trim($home ?? '');
        if ($home !== '') {
            $cpanel = self::tryRoot(rtrim($home, DIRECTORY_SEPARATOR) . '/arasya-operations-api/current');
            if ($cpanel !== null) {
                return $cpanel;
            }
        }

        $publicRoot = self::resolvedDirectory($publicRoot);
        if ($publicRoot !== null) {
            $derivedHome = dirname($publicRoot);
            if ($derivedHome !== DIRECTORY_SEPARATOR) {
                $cpanel = self::tryRoot($derivedHome . '/arasya-operations-api/current');
                if ($cpanel !== null) {
                    return $cpanel;
                }
            }
        }

        throw new RuntimeException('Operations API runtime is unavailable.');
    }

    private static function resolvedDirectory(string $candidate): ?string
    {
        if (!str_starts_with($candidate, DIRECTORY_SEPARATOR) || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $candidate) === 1) {
            return null;
        }
        $resolved = realpath($candidate);
        return $resolved !== false && is_dir($resolved) ? $resolved : null;
    }

    private static function tryRoot(string $candidate): ?string
    {
        try {
            return self::validatedRoot($candidate);
        } catch (RuntimeException) {
            return null;
        }
    }

    private static function validatedRoot(string $candidate): string
    {
        if (!str_starts_with($candidate, DIRECTORY_SEPARATOR) || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $candidate) === 1) {
            throw new RuntimeException('Operations API runtime path is invalid.');
        }
        $root = realpath($candidate);
        $bootstrap = realpath(rtrim($candidate, DIRECTORY_SEPARATOR) . '/bootstrap.php');
        if ($root === false || $bootstrap === false || !is_file($bootstrap) || dirname($bootstrap) !== $root) {
            throw new RuntimeException('Operations API runtime is unavailable.');
        }
        return $root;
    }
}
