<?php

declare(strict_types=1);

// Explicit activation control of Trendyol intake (operator only; changes no order, permission or grant).
//
//   php bin/trendyol-intake.php status
//   php bin/trendyol-intake.php activate --baseline=now|<ISO-8601> --operator=<name> --confirm=ACTIVATE-TRENDYOL-INTAKE
//   php bin/trendyol-intake.php pause --operator=<name>
//   php bin/trendyol-intake.php resume --operator=<name>
//
// Activation records the baseline: Trendyol packages ordered before it are historical and never become intake
// work. The baseline cannot lie more than five minutes in the past and never moves backwards. Activation alone
// reads nothing: the cron launcher and ARASYA_TRENDYOL_INTAKE = 'enabled' are separate switches.

$container = require __DIR__ . '/cli-bootstrap.php';
$command = $argv[1] ?? 'status';
$options = getopt('', ['baseline:', 'operator:', 'confirm:'], $rest);
$state = $container->trendyolIntakeState();
$config = $container->config();
$print = static function (array $state) use ($config): void {
    fwrite(STDOUT, json_encode($state + [
        'credentialsConfigured' => $config->trendyol !== null,
        'intakeSwitch' => $config->trendyolIntakeEnabled() ? 'enabled' : 'disabled',
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
};
try {
    switch ($command) {
        case 'status':
            $print($state->read());
            break;
        case 'activate':
            if (($options['confirm'] ?? null) !== 'ACTIVATE-TRENDYOL-INTAKE') {
                fwrite(STDERR, "Refused: --confirm=ACTIVATE-TRENDYOL-INTAKE is required.\n");
                exit(2);
            }
            $value = (string) ($options['baseline'] ?? '');
            $baseline = $value === 'now' ? $container->clock()->now() : DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
            if (!$baseline instanceof DateTimeImmutable) {
                fwrite(STDERR, "Refused: --baseline must be 'now' or ISO-8601.\n");
                exit(2);
            }
            $print($state->activate($baseline, (string) ($options['operator'] ?? '')));
            break;
        case 'pause':
            $print($state->pause((string) ($options['operator'] ?? '')));
            break;
        case 'resume':
            $print($state->resume((string) ($options['operator'] ?? '')));
            break;
        default:
            fwrite(STDERR, "Usage: php bin/trendyol-intake.php status|activate|pause|resume [options]\n");
            exit(2);
    }
} catch (RuntimeException $error) {
    fwrite(STDERR, (preg_match('/^TRENDYOL_[A-Z_]+$/D', $error->getMessage()) === 1 ? $error->getMessage() : 'TRENDYOL_INTAKE_COMMAND_FAILED') . "\n");
    exit(1);
}
