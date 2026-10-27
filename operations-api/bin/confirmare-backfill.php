<?php

declare(strict_types=1);

// The 22 October backfill (owner decision K-06, API 2.46.0): orders a shop submitted from 2026-10-22 00:00
// Europe/Bucharest until its first Confirmare activation, still before production, are taken into Confirmare; orders
// already in production keep their stage and documents (ConfirmareBackfill). Read-only unless --apply.
//
//   php bin/confirmare-backfill.php --source=trendhome|outletperdele --actor=<employee uuid> [--until=YYYY-MM-DD]
//       [--apply] [--limit=500] [--pace-ms=400]
//
// API 2.48.0 (owner decision 2026-10-27, YD SOFT 2.22.0): every order Arasya would take is first adopted by the shop,
// signed with its source access key (dry run: the shop only answers). --actor names the employee recorded as the actor
// of the adoption; --until ends the window at 00:00 Europe/Bucharest of that day (the owner's decision: --until=2026-10-27),
// never later than the activation; --pace-ms spaces the shop requests (its source access is shared with Staff).
//
// Run the shop's own backfill first (`wp yd-arasya backfill --apply`) so every order of the window reached Arasya.
// Prints counts, order numbers and the shop's refusal reasons only: no customer data, no amounts.

use Arasya\Operations\Confirmare\ConfirmareBackfill;
use Arasya\Operations\Integration\SourceAccess\SourceAccessClient;
use Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry;
use Arasya\Operations\Integration\SourceAccess\StreamSourceAccessTransport;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['source:', 'apply', 'limit:', 'until:', 'actor:', 'pace-ms:']);
$usage = "Usage: php bin/confirmare-backfill.php --source=trendhome|outletperdele --actor=<employee uuid> [--until=YYYY-MM-DD] [--apply] [--limit=500] [--pace-ms=400]\n";
$source = is_string($options['source'] ?? null) ? $options['source'] : '';
$actor = is_string($options['actor'] ?? null) ? $options['actor'] : '';
if (!in_array($source, ['trendhome', 'outletperdele'], true) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $actor) !== 1) {
    fwrite(STDERR, $usage);
    exit(2);
}
$pdo = $container->pdo();
$employee = $pdo->prepare("SELECT status FROM employees WHERE employee_uuid = ?");
$employee->execute([$actor]);
if ($employee->fetchColumn() !== 'active') {
    fwrite(STDERR, "--actor must name an active employee.\n");
    exit(2);
}
$until = null;
if (isset($options['until'])) {
    $day = is_string($options['until']) ? $options['until'] : '';
    $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) === 1 ? DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('Europe/Bucharest')) : false;
    if ($parsed === false || $parsed->format('Y-m-d') !== $day) {
        fwrite(STDERR, $usage);
        exit(2);
    }
    $until = $parsed->setTimezone(new DateTimeZone('UTC'));
}
$limit = isset($options['limit']) && is_string($options['limit']) && ctype_digit($options['limit']) ? (int) $options['limit'] : 500;
$pace = isset($options['pace-ms']) && is_string($options['pace-ms']) && ctype_digit($options['pace-ms']) ? min(5000, (int) $options['pace-ms']) : 400;
$config = $container->config();
$backfill = new ConfirmareBackfill($pdo, $container->clock(), SourceAccessRegistry::fromConfig($config),
    new SourceAccessClient(new StreamSourceAccessTransport(allowLoopback: !$config->isProduction()), $container->clock()), $actor, $pace * 1000);
$result = $backfill->run($source, isset($options['apply']), $limit, $until);
fwrite(STDOUT, json_encode($result + ['apply' => isset($options['apply'])], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
exit(0);
