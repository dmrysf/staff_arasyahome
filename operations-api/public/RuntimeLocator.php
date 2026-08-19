<?php

declare(strict_types=1);

namespace Arasya\Operations\Bootstrap;

use RuntimeException;

final class RuntimeLocator
{
    private const POINTER_MAX_BYTES = 128;

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
            $cpanel = self::runtimeFromHome($home);
            if ($cpanel !== null) {
                return $cpanel;
            }
        }

        $publicRoot = self::resolvedDirectory($publicRoot);
        if ($publicRoot !== null) {
            $derivedHome = dirname($publicRoot);
            if ($derivedHome !== DIRECTORY_SEPARATOR && basename($publicRoot) === 'api.arasyahome.ro') {
                $cpanel = self::runtimeFromHome($derivedHome);
                if ($cpanel !== null) {
                    return $cpanel;
                }
            }
        }

        throw new RuntimeException('Operations API runtime is unavailable.');
    }

    private static function runtimeFromHome(string $home): ?string
    {
        $resolvedHome = self::resolvedDirectory($home);
        if ($resolvedHome === null || $resolvedHome === DIRECTORY_SEPARATOR) {
            return null;
        }
        $applicationRoot = $resolvedHome . '/arasya-operations-api';
        $pointer = $applicationRoot . '/active-release';
        if (file_exists($pointer) || is_link($pointer)) {
            return self::rootFromPointer($applicationRoot, $pointer);
        }
        return self::tryRoot($applicationRoot . '/current');
    }

    private static function rootFromPointer(string $applicationRoot, string $pointer): string
    {
        if (!is_file($pointer) || is_link($pointer)) {
            throw new RuntimeException('Operations API active release pointer is invalid.');
        }
        $size = filesize($pointer);
        if (!is_int($size) || $size < 1 || $size > self::POINTER_MAX_BYTES) {
            throw new RuntimeException('Operations API active release pointer is invalid.');
        }
        $raw = file_get_contents($pointer, false, null, 0, self::POINTER_MAX_BYTES + 1);
        $sourceCommit = is_string($raw) ? trim($raw) : '';
        if (preg_match('/^[0-9a-f]{40}$/', $sourceCommit) !== 1) {
            throw new RuntimeException('Operations API active release pointer is invalid.');
        }

        $releasesRoot = self::resolvedDirectory($applicationRoot . '/releases');
        if ($releasesRoot === null || dirname($releasesRoot) !== self::resolvedDirectory($applicationRoot)) {
            throw new RuntimeException('Operations API releases root is unavailable.');
        }
        $target = self::validatedRoot($releasesRoot . '/' . $sourceCommit);
        if (dirname($target) !== $releasesRoot || basename($target) !== $sourceCommit) {
            throw new RuntimeException('Operations API active release escaped the releases root.');
        }
        return $target;
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
