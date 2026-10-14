<?php

declare(strict_types=1);

// Controlled activation of per-piece (partial) defect reports, migration 028 (operator only; changes no order,
// permission or grant).
//
//   php bin/partial-rework.php status
//   php bin/partial-rework.php enable --operator=<name> --confirm=ENABLE-PARTIAL-REWORK
//   php bin/partial-rework.php disable --operator=<name>
//   php bin/partial-rework.php backfill --operator=<name> [--limit=<orders>]
//
// Enable only after API 2.31.0, Staff 2.26.0 and Dashboard 0.14.0 are all live. Disabling refuses new partial reports
// and leaves every piece already in rework on its track until it rejoins its order at packing. The backfill writes the
// piece identities of open orders (idempotent; a re-run writes nothing new); pieces are otherwise written on first use.
//
// Exit codes: 0 done; 1 failed; 2 invalid invocation (nothing read or changed). Messages never echo an argument value.

use Arasya\Operations\Production\WorkUnits;

$commands = ['status' => [], 'enable' => ['operator', 'confirm'], 'disable' => ['operator'], 'backfill' => ['operator']];
$refuse = static function (string $message): never {
    fwrite(STDERR, "Refused: {$message}\nUsage: php bin/partial-rework.php status|enable|disable|backfill [--name=value ...]\n");
    exit(2);
};
$arguments = array_slice($argv, 1);
$command = $arguments === [] ? 'status' : (string) array_shift($arguments);
if (!array_key_exists($command, $commands)) {
    $refuse('unsupported command.');
}
$options = [];
foreach ($arguments as $argument) {
    if (preg_match('/^--([a-z]+)=(.*)$/sD', (string) $argument, $match) !== 1) {
        $refuse('unexpected argument; options are --name=value.');
    }
    if (!in_array($match[1], [...$commands[$command], ...($command === 'backfill' ? ['limit'] : [])], true) || array_key_exists($match[1], $options)) {
        $refuse("option --{$match[1]} is not accepted here.");
    }
    $options[$match[1]] = $match[2];
}
foreach ($commands[$command] as $required) {
    if (trim($options[$required] ?? '') === '') {
        $refuse("--{$required} is required.");
    }
}
if ($command === 'enable' && $options['confirm'] !== 'ENABLE-PARTIAL-REWORK') {
    $refuse('the confirmation phrase does not match.');
}
$limit = isset($options['limit']) ? (ctype_digit($options['limit']) && (int) $options['limit'] > 0 ? (int) $options['limit'] : $refuse('--limit must be a positive integer.')) : 500;

$container = require __DIR__ . '/cli-bootstrap.php';
$pdo = $container->pdo();
$units = new WorkUnits($pdo);
$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
try {
    if ($command === 'enable' || $command === 'disable') {
        $pdo->beginTransaction();
        $result = $units->setPartialRework($command === 'enable', ($command === 'enable' ? 'Enabled' : 'Disabled') . ' by operator ' . mb_substr($options['operator'], 0, 80), $now);
        $pdo->commit();
        fwrite(STDOUT, json_encode(['partialRework' => $result['enabled'], 'changed' => $result['changed']] + $units->inventory(), JSON_THROW_ON_ERROR) . "\n");
        exit(0);
    }
    if ($command === 'backfill') {
        $orders = $pdo->query("SELECT o.order_uuid FROM operational_orders o WHERE o.production_completed_at IS NULL AND o.operational_status <> 'unavailable'
            AND NOT EXISTS (SELECT 1 FROM production_work_units u WHERE u.order_uuid = o.order_uuid) ORDER BY o.created_at LIMIT {$limit}")->fetchAll(PDO::FETCH_COLUMN);
        $written = 0;
        foreach ($orders as $orderUuid) {
            // One short transaction per order, under the same order row lock every production mutation takes.
            $pdo->beginTransaction();
            $pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid = ? FOR UPDATE')->execute([$orderUuid]);
            $units->ensure((string) $orderUuid, $now, 'backfill');
            $pdo->commit();
            $written++;
        }
        fwrite(STDOUT, json_encode(['ordersMaterialized' => $written, 'more' => count($orders) === $limit] + $units->inventory(), JSON_THROW_ON_ERROR) . "\n");
        exit(0);
    }
    fwrite(STDOUT, json_encode(['partialRework' => $units->partialReworkEnabled()] + $units->inventory(), JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Failed: ' . get_class($error) . "\n");
    exit(1);
}
