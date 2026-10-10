<?php

declare(strict_types=1);

// The real `bin/trendyol-intake.php` executable on a disposable database: argument parsing (command first, then
// --name=value options), exit codes (0 done, 1 refused by the intake state, 2 invalid invocation), messages that
// never echo argument values, and the unchanged TrendyolIntakeState rules (confirmation, operator, baseline slack,
// forward-only baseline, pause/resume transitions, audit events, cursor untouched by pause/resume).
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$db = T::requireTestDatabase();
if ($db === null) { echo "SKIP Trendyol intake CLI: test database not configured\n"; exit; }
$pdo = Connection::create(T::config($db));
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
$pdo->exec('DELETE FROM trendyol_intake_events WHERE package_id IS NULL');
$pdo->exec("UPDATE trendyol_intake_state SET status = 'inactive', baseline_at = NULL, cursor_at = NULL, activated_at = NULL, activated_by = NULL, last_run_at = NULL, last_run_outcome = NULL");

$home = sys_get_temp_dir() . '/arasya-trendyol-cli-' . bin2hex(random_bytes(4));
mkdir($home, 0700);
$environment = [
    'HOME' => $home,
    'ARASYA_APP_ENV' => 'test',
    'ARASYA_APP_SECRET' => str_repeat('t', 32),
    'ARASYA_ALLOWED_ORIGINS' => T::ORIGIN,
    'ARASYA_DB_HOST' => (string) (getenv('ARASYA_TEST_DB_HOST') ?: '127.0.0.1'),
    'ARASYA_DB_PORT' => (string) (getenv('ARASYA_TEST_DB_PORT') ?: '3306'),
    'ARASYA_DB_NAME' => $db,
    'ARASYA_DB_USER' => (string) getenv('ARASYA_TEST_DB_USER'),
    'ARASYA_DB_PASSWORD' => (string) getenv('ARASYA_TEST_DB_PASSWORD'),
];
/** @return array{code: int, out: string, err: string} */
$cli = static function (string ...$arguments) use ($environment): array {
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/trendyol-intake.php', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
};
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$one = static function (string $sql) use ($pdo): mixed { return $pdo->query($sql)->fetchColumn(); };
$state = static fn (): array => $pdo->query('SELECT status, baseline_at, cursor_at, activated_by, changed_by FROM trendyol_intake_state WHERE state_id = 1')->fetch(PDO::FETCH_ASSOC);
$events = static fn (): array => $pdo->query('SELECT action, actor_label FROM trendyol_intake_events WHERE package_id IS NULL ORDER BY event_id')->fetchAll(PDO::FETCH_ASSOC);
$json = static function (array $run) use ($check): array {
    $decoded = json_decode($run['out'], true);
    $check(is_array($decoded), 'stdout is the JSON state: ' . $run['out'] . $run['err']);
    return $decoded;
};
// An invalid invocation exits 2, explains itself, and reads or changes nothing.
$invalid = static function (array $arguments, string $message, string $label) use ($cli, $check, $state, $events): void {
    $before = [$state(), $events()];
    $run = $cli(...$arguments);
    $check($run['code'] === 2, "{$label}: exit 2, got {$run['code']} {$run['err']}");
    $check(str_contains($run['err'], $message), "{$label}: message '{$message}' in: {$run['err']}");
    $check($run['out'] === '', "{$label}: nothing on stdout");
    $check([$state(), $events()] === $before, "{$label}: state and audit unchanged");
};
// The intake state refuses: exit 1 with its TRENDYOL_* code only, nothing changed.
$refused = static function (array $arguments, string $code, string $label) use ($cli, $check, $state, $events): void {
    $before = [$state(), $events()];
    $run = $cli(...$arguments);
    $check($run['code'] === 1 && trim($run['err']) === $code, "{$label}: exit 1 {$code}, got {$run['code']} {$run['err']}");
    $check([$state(), $events()] === $before, "{$label}: state and audit unchanged");
};
$confirm = '--confirm=ACTIVATE-TRENDYOL-INTAKE';
$operator = '--operator=CLI Test Operator';

// ---- status --------------------------------------------------------------------------------------------------
foreach ([[], ['status']] as $arguments) {
    $run = $cli(...$arguments);
    $status = $json($run);
    $check($run['code'] === 0 && $run['err'] === '', 'status exits 0 silently on stderr');
    $check($status['status'] === 'inactive' && $status['baselineAt'] === null && $status['cursorAt'] === null, 'status reports the inactive state');
    $check($status['credentialsConfigured'] === false && $status['intakeSwitch'] === 'disabled', 'status reports credentials and switch without values');
}
$invalid(['status', '--operator=x'], 'unknown option --operator for status', 'status takes no option');

// ---- invalid invocations: nothing is read or changed ----------------------------------------------------------
$invalid(['backfill'], 'unsupported command', 'unsupported command');
$invalid(['ACTIVATE'], 'unsupported command', 'commands are case-sensitive');
$invalid([$operator, 'pause'], 'unsupported command', 'the command comes first');
$invalid(['activate', '--baseline=now', $operator], '--confirm is required for activate', 'activation without confirmation');
$invalid(['activate', '--baseline=now', $operator, '--confirm=yes'], '--confirm=ACTIVATE-TRENDYOL-INTAKE is required', 'activation with the wrong confirmation');
$invalid(['activate', '--baseline=now', $operator, '--confirm=activate-trendyol-intake'], '--confirm=ACTIVATE-TRENDYOL-INTAKE is required', 'the confirmation is case-sensitive');
$invalid(['activate', $operator, $confirm], '--baseline is required for activate', 'activation without baseline');
$invalid(['activate', '--baseline=now', $confirm], '--operator is required for activate', 'activation without operator');
foreach (['yesterday', '2026-10-10 16:48:04', '2026-10-10T16:48:04', '2026-02-30T10:00:00+00:00', '1791607684074'] as $value) {
    $invalid(['activate', "--baseline={$value}", $operator, $confirm], "--baseline must be 'now' or ISO-8601", "baseline '{$value}' is refused");
}
$invalid(['activate', '--baseline=', $operator, $confirm], '--baseline is empty', 'an empty baseline');
$invalid(['activate', '--baseline=now', '--operator=  ', $confirm], '--operator is empty', 'a blank operator');
$invalid(['activate', '--baseline', 'now', $operator, $confirm], 'unexpected argument', 'space-separated option values');
$invalid(['activate', '--baseline=now', $operator, $confirm, '--force=1'], 'unknown option --force for activate', 'unknown option');
$invalid(['activate', '--baseline=now', $operator, $confirm, '--backfill'], 'unexpected argument', 'option without value');
$invalid(['activate', '--baseline=now', $operator, $operator, $confirm], '--operator is given more than once', 'duplicate operator');
$invalid(['activate', '--baseline=now', '--baseline=now', $operator, $confirm], '--baseline is given more than once', 'duplicate baseline');
$invalid(['activate', '-b', 'now'], 'unexpected argument', 'short options');
$invalid(['pause'], '--operator is required for pause', 'pause without operator');
$invalid(['resume'], '--operator is required for resume', 'resume without operator');
$invalid(['pause', $operator, '--baseline=now'], 'unknown option --baseline for pause', 'pause takes only the operator');
$invalid(['resume', $operator, $operator], '--operator is given more than once', 'duplicate operator on resume');
// Messages never echo a value, even one pasted by mistake.
foreach ([['activate', '--apikey=PASTED-SECRET-VALUE'], ['activate', 'PASTED-SECRET-VALUE'], ['activate', '--baseline=PASTED-SECRET-VALUE', $operator, $confirm], ['pause', '--operator=PASTED-SECRET-VALUE', '--operator=PASTED-SECRET-VALUE']] as $arguments) {
    $run = $cli(...$arguments);
    $check($run['code'] === 2 && !str_contains($run['out'] . $run['err'], 'PASTED-SECRET-VALUE'), 'a refused argument value is never echoed');
}

// ---- transitions refused by the intake state (unchanged rules) -------------------------------------------------
$refused(['pause', $operator], 'TRENDYOL_INTAKE_NOT_ACTIVE', 'pause while inactive');
$refused(['resume', $operator], 'TRENDYOL_INTAKE_NOT_PAUSED', 'resume while inactive');
$refused(['activate', '--baseline=' . gmdate(DATE_ATOM, time() - 3600), $operator, $confirm], 'TRENDYOL_BASELINE_IN_PAST', 'a baseline an hour ago would import history');
$refused(['activate', '--baseline=' . gmdate(DATE_ATOM, time() + 8 * 86400), $operator, $confirm], 'TRENDYOL_BASELINE_TOO_FAR', 'a baseline beyond seven days');
$refused(['activate', '--baseline=now', '--operator=x', $confirm], 'TRENDYOL_OPERATOR_REQUIRED', 'an operator name that is too short');
$refused(['activate', '--baseline=now', '--operator=Owner (approved)', $confirm], 'TRENDYOL_OPERATOR_REQUIRED', 'an operator name with unsupported characters');
$check($one('SELECT status FROM trendyol_intake_state WHERE state_id = 1') === 'inactive' && $events() === [], 'every refusal left the intake inactive and unaudited');

// ---- activate --baseline=now ------------------------------------------------------------------------------------
$before = microtime(true);
$run = $cli('activate', '--baseline=now', $operator, $confirm);
$after = microtime(true);
$activated = $json($run);
$check($run['code'] === 0 && $run['err'] === '', 'activation exits 0: ' . $run['err']);
$baselineEpoch = (float) (new DateTimeImmutable($activated['baselineAt'] . ' UTC'))->format('U.u');
$check($activated['status'] === 'active' && $baselineEpoch >= $before - 1 && $baselineEpoch <= $after + 1, 'baseline=now is the activation instant');
$check($activated['cursorAt'] === $activated['baselineAt'], 'the cursor starts at the baseline');
$check($activated['activatedBy'] === 'CLI Test Operator', 'the operator is recorded');
$check($events() === [['action' => 'intake_activated', 'actor_label' => 'CLI Test Operator']], 'activation is audited once with the operator');
$baseline = $state()['baseline_at'];
$refused(['activate', '--baseline=now', $operator, $confirm], 'TRENDYOL_INTAKE_ALREADY_ACTIVE', 'activation while active');
$refused(['resume', $operator], 'TRENDYOL_INTAKE_NOT_PAUSED', 'resume while active');

// A sync run advances the cursor; pause and resume never touch it or the baseline.
$pdo->exec("UPDATE trendyol_intake_state SET cursor_at = DATE_ADD(baseline_at, INTERVAL 10 MINUTE) WHERE state_id = 1");
$cursor = $state()['cursor_at'];

// ---- pause / resume ---------------------------------------------------------------------------------------------
$run = $cli('pause', '--operator=Pause Operator');
$check($run['code'] === 0 && $json($run)['status'] === 'paused', 'pause exits 0 and pauses');
$check($state()['changed_by'] === 'Pause Operator' && $state()['baseline_at'] === $baseline && $state()['cursor_at'] === $cursor, 'pause keeps baseline and cursor');
$refused(['pause', $operator], 'TRENDYOL_INTAKE_NOT_ACTIVE', 'pause while paused');
$refused(['activate', '--baseline=' . (new DateTimeImmutable($baseline . ' UTC'))->modify('-60 seconds')->format(DATE_ATOM), $operator, $confirm], 'TRENDYOL_BASELINE_BACKWARDS', 'a re-baseline never moves backwards');
$run = $cli('resume', '--operator=Resume Operator');
$check($run['code'] === 0 && $json($run)['status'] === 'active', 'resume exits 0 and resumes');
$check($state()['changed_by'] === 'Resume Operator' && $state()['baseline_at'] === $baseline && $state()['cursor_at'] === $cursor, 'resume keeps baseline and cursor');
$check(array_column($events(), 'action') === ['intake_activated', 'intake_paused', 'intake_resumed'], 'each transition is audited once; refusals add nothing');
$check(array_column($events(), 'actor_label') === ['CLI Test Operator', 'Pause Operator', 'Resume Operator'], 'each event names its operator');

// ---- forward re-baseline while paused, with an explicit ISO-8601 baseline ---------------------------------------
$check($cli('pause', $operator)['code'] === 0, 'paused again');
$forward = gmdate(DATE_ATOM, time() + 3600);
$run = $cli('activate', '--baseline=' . str_replace('+00:00', 'Z', $forward), $operator, $confirm);
$rebaselined = $json($run);
$check($run['code'] === 0 && $rebaselined['status'] === 'active', 'a forward ISO-8601 re-baseline while paused is accepted');
$check(str_starts_with($rebaselined['baselineAt'], str_replace('T', ' ', substr($forward, 0, 19))) && $rebaselined['cursorAt'] === $rebaselined['baselineAt'], 'the UTC baseline and cursor move to it');
$check(count($events()) === 5, 'the re-baseline is audited');

// ---- leave the disposable intake inactive ------------------------------------------------------------------------
$pdo->exec('DELETE FROM trendyol_intake_events WHERE package_id IS NULL');
$pdo->exec("UPDATE trendyol_intake_state SET status = 'inactive', baseline_at = NULL, cursor_at = NULL, activated_at = NULL, activated_by = NULL, last_run_at = NULL, last_run_outcome = NULL");
rmdir($home);

echo "PASS {$checks} Trendyol intake CLI checks\n";
