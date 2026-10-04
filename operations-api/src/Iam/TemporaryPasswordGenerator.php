<?php

declare(strict_types=1);

namespace Arasya\Operations\Iam;

/** Cryptographically random one-time passwords without visually ambiguous characters. */
final class TemporaryPasswordGenerator
{
    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const LOWER = 'abcdefghijkmnopqrstuvwxyz';
    private const DIGITS = '23456789';
    private const SYMBOLS = '-_.!@#%+=';

    public static function generate(int $length = 24): string
    {
        if ($length < 16) {
            throw new \InvalidArgumentException('Temporary passwords must have at least 16 characters.');
        }
        $all = self::UPPER . self::LOWER . self::DIGITS . self::SYMBOLS;
        $characters = [self::pick(self::UPPER), self::pick(self::LOWER), self::pick(self::DIGITS), self::pick(self::SYMBOLS)];
        while (count($characters) < $length) {
            $characters[] = self::pick($all);
        }
        for ($index = count($characters) - 1; $index > 0; $index--) {
            $swap = random_int(0, $index);
            [$characters[$index], $characters[$swap]] = [$characters[$swap], $characters[$index]];
        }
        return implode('', $characters);
    }

    private static function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}
