<?php

declare(strict_types=1);

// ING advance reconciliation, Operations guard (API 2.58.0, Phase 0, issue #114). READ-ONLY: SELECTs only, no lock, no
// write, no audit row, no finance task, no finance action, no shop call.
//
//   php bin/ing-settlement-guard.php --snapshot
//        a digest of the human finance workflow (every finance verification request with its state, version, assignee,
//        checker and outcome, every request event, every finance action, every finance scope) and its counts. Run it
//        before and after any shadow run: the digest must be identical.
//   php bin/ing-settlement-guard.php --orders=trendhome:64871,outletperdele:60500
//        for the shop's shadow proposals (`wp yd-arasya ing preview`), whether Operations would refuse an automatic
//        settlement, with every reason (an existing finance task of any state refuses). `auto_apply_unavailable` is always
//        listed: this version cannot apply anything.
//
// Prints order ids, codes and counts only (no customer data, no amount, no bank data).

use Arasya\Operations\Confirmare\IngSettlementGuard;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['snapshot', 'orders:']);
if (!isset($options['snapshot']) && !is_string($options['orders'] ?? null)) {
    fwrite(STDERR, "Usage: php bin/ing-settlement-guard.php --snapshot | --orders=<source:id,...>\n");
    exit(2);
}
$guard = new IngSettlementGuard($container->pdo());
$result = ['readOnly' => true, 'autoApplyAvailable' => IngSettlementGuard::AUTO_APPLY_AVAILABLE];
if (isset($options['snapshot'])) {
    $result['snapshot'] = $guard->snapshot();
}
if (is_string($options['orders'] ?? null)) {
    $result += $guard->verdicts(array_map('trim', explode(',', $options['orders'])));
}
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
exit(0);
