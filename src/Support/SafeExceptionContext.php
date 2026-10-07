<?php

declare(strict_types=1);

namespace Arasya\Operations\Support;

use PDOException;
use Throwable;

/**
 * Safe, structured diagnostics for unexpected exceptions.
 *
 * Only the exception class and, for PDO failures, the SQLSTATE and the numeric driver error code are
 * returned. The values come from structured properties (errorInfo, code) and are format-validated, so
 * an exception message, SQL text, bound parameters or row values can never reach the log.
 */
final class SafeExceptionContext
{
    private const MAX_CHAIN = 3;

    private function __construct()
    {
    }

    /** @return array<string, string|int> */
    public static function of(Throwable $error): array
    {
        $context = ['exception' => $error::class];
        $pdo = self::firstPdoException($error);
        if ($pdo === null) {
            return $context;
        }
        if ($pdo !== $error) {
            $context['cause'] = $pdo::class;
        }
        $sqlState = self::sqlState($pdo);
        if ($sqlState !== null) {
            $context['sqlstate'] = $sqlState;
        }
        $driverCode = self::driverCode($pdo);
        if ($driverCode !== null) {
            $context['driver_code'] = $driverCode;
        }
        return $context;
    }

    private static function firstPdoException(Throwable $error): ?PDOException
    {
        for ($current = $error, $depth = 0; $current !== null && $depth < self::MAX_CHAIN; $current = $current->getPrevious(), $depth++) {
            if ($current instanceof PDOException) {
                return $current;
            }
        }
        return null;
    }

    private static function sqlState(PDOException $error): ?string
    {
        $candidates = [is_array($error->errorInfo) ? ($error->errorInfo[0] ?? null) : null, $error->getCode()];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^[0-9A-Z]{5}$/D', $candidate) === 1 && $candidate !== '00000') {
                return $candidate;
            }
        }
        return null;
    }

    private static function driverCode(PDOException $error): ?int
    {
        // Query errors carry the driver code in errorInfo[1]; connection errors carry it as the integer code.
        $candidates = [is_array($error->errorInfo) ? ($error->errorInfo[1] ?? null) : null, $error->getCode()];
        foreach ($candidates as $candidate) {
            if (is_int($candidate) && $candidate > 0 && $candidate < 100000) {
                return $candidate;
            }
        }
        return null;
    }
}
