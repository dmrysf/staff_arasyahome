<?php

declare(strict_types=1);

namespace Arasya\Operations\Support;

use RuntimeException;

final class CliInput
{
    public static function prompt(string $label, ?string $default = null): string
    {
        $suffix = $default === null ? '' : " [{$default}]";
        fwrite(STDOUT, "{$label}{$suffix}: ");
        $value = fgets(STDIN);
        if ($value === false) {
            throw new RuntimeException('Could not read terminal input.');
        }
        $value = trim($value);
        return $value === '' && $default !== null ? $default : $value;
    }

    public static function secret(string $label): string
    {
        fwrite(STDOUT, "{$label}: ");
        $canHide = function_exists('shell_exec')
            && DIRECTORY_SEPARATOR === '/'
            && trim((string) shell_exec('stty -echo 2>/dev/null && printf ok')) === 'ok';
        if (!$canHide) {
            fwrite(STDERR, "\nWarning: terminal echo could not be disabled.\n");
        }
        try {
            $value = fgets(STDIN);
        } finally {
            if ($canHide) {
                shell_exec('stty echo');
            }
            fwrite(STDOUT, PHP_EOL);
        }
        if ($value === false) {
            throw new RuntimeException('Could not read terminal secret.');
        }
        return rtrim($value, "\r\n");
    }
}
