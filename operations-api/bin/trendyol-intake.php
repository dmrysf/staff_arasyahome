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
//
// The command comes first; every option is `--name=value`, at most once. PHP getopt() stops at the first
// non-option argument, so it never saw the options after the command: activate was always refused and pause or
// resume were refused for a missing operator. Arguments are parsed here instead, before any configuration or
// database access.
//
// Exit codes: 0 done; 1 refused by the intake state (TRENDYOL_* code) or failed; 2 invalid invocation (nothing
// read or changed). Messages never echo an argument value.

$commands = [
    'status' => ['required' => [], 'optional' => []],
    'activate' => ['required' => ['baseline', 'operator', 'confirm'], 'optional' => []],
    'pause' => ['required' => ['operator'], 'optional' => []],
    'resume' => ['required' => ['operator'], 'optional' => []],
];
$refuse = static function (string $message): never {
    fwrite(STDERR, "Refused: {$message}\nUsage: php bin/trendyol-intake.php status|activate|pause|resume [--name=value ...]\n");
    exit(2);
};
$arguments = array_slice($argv, 1);
$command = $arguments === [] ? 'status' : (string) array_shift($arguments);
if (!array_key_exists($command, $commands)) {
    $refuse('unsupported command.');
}
$allowed = [...$commands[$command]['required'], ...$commands[$command]['optional']];
$options = [];
foreach ($arguments as $argument) {
    if (preg_match('/^--([a-z]+)=(.*)$/sD', (string) $argument, $match) !== 1) {
        $refuse('unexpected argument; options are --name=value.');
    }
    [, $name, $value] = $match;
    if (!in_array($name, $allowed, true)) {
        $refuse("unknown option --{$name} for {$command}.");
    }
    if (array_key_exists($name, $options)) {
        $refuse("--{$name} is given more than once.");
    }
    if (trim($value) === '') {
        $refuse("--{$name} is empty.");
    }
    $options[$name] = $value;
}
foreach ($commands[$command]['required'] as $name) {
    if (!array_key_exists($name, $options)) {
        $refuse("--{$name} is required for {$command}.");
    }
}
$baseline = null;
if ($command === 'activate') {
    if ($options['confirm'] !== 'ACTIVATE-TRENDYOL-INTAKE') {
        $refuse('--confirm=ACTIVATE-TRENDYOL-INTAKE is required.');
    }
    if ($options['baseline'] !== 'now') {
        // Strict ISO-8601 with an offset; an overflowing date (2026-02-30) is refused, not rolled over.
        $baseline = DateTimeImmutable::createFromFormat('!' . DATE_ATOM, $options['baseline']);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$baseline instanceof DateTimeImmutable || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            $refuse("--baseline must be 'now' or ISO-8601 with an offset (2026-10-10T16:48:04+00:00).");
        }
    }
}

$container = require __DIR__ . '/cli-bootstrap.php';
try {
    $state = $container->trendyolIntakeState();
    $result = match ($command) {
        'status' => $state->read(),
        // 'now' is read only here, after parsing, so the slack check sees the real activation instant.
        'activate' => $state->activate($baseline ?? $container->clock()->now(), $options['operator']),
        'pause' => $state->pause($options['operator']),
        'resume' => $state->resume($options['operator']),
    };
} catch (RuntimeException $error) {
    fwrite(STDERR, (preg_match('/^TRENDYOL_[A-Z_]+$/D', $error->getMessage()) === 1 ? $error->getMessage() : 'TRENDYOL_INTAKE_COMMAND_FAILED') . "\n");
    exit(1);
}
$config = $container->config();
fwrite(STDOUT, json_encode($result + [
    'credentialsConfigured' => $config->trendyol !== null,
    'intakeSwitch' => $config->trendyolIntakeEnabled() ? 'enabled' : 'disabled',
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
