<?php

declare(strict_types=1);

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\B2B\FinanceGate;
use Arasya\Operations\B2B\FinanceGateReadiness;

// Read-only readiness report for the B2B finance gate (API 2.67.0, owner decisions D2 and D3): what `enforce` would decide
// now for every open B2B order, and how many active companies would pass a finalization. It runs in a READ ONLY
// transaction, writes no journal row, never changes ARASYA_B2B_FINANCE_GATE and prints no amount, name or contact.
//   php bin/b2b-finance-gate-readiness.php             counts and reason codes
//   php bin/b2b-finance-gate-readiness.php --detail    also internal order and company codes with their reason codes
$container = require __DIR__ . '/cli-bootstrap.php';
$detail = in_array('--detail', array_slice($argv, 1), true);
if (array_diff(array_slice($argv, 1), ['--detail']) !== []) {
    fwrite(STDERR, "Usage: php bin/b2b-finance-gate-readiness.php [--detail]\n");
    exit(1);
}
$pdo = $container->pdo();
$authorization = new AuthorizationService();
$config = $container->config();
$pdo->exec('START TRANSACTION READ ONLY');
try {
    $report = FinanceGateReadiness::report($pdo, $container->b2bFinanceGateWithModes($authorization, FinanceGate::ENFORCE, FinanceGate::ENFORCE),
        $container->b2bFinanceProfileReader($authorization), $detail);
} finally {
    $pdo->exec('ROLLBACK');
}
$release = $config->b2bFinanceReleaseGateMode();
fwrite(STDOUT, 'INFO configured finalization=' . (in_array($config->b2bFinanceGateMode(), FinanceGate::MODES, true) ? $config->b2bFinanceGateMode() : 'INVALID')
    . ' release=' . ($release === null ? 'inherited' : (in_array($release, FinanceGate::MODES, true) ? $release : 'INVALID')) . "\n");
fwrite(STDOUT, "INFO evaluated as enforce; nothing is written\n");
foreach ($report['orders'] as $key => $count) fwrite(STDOUT, "ORDERS {$key} {$count}\n");
foreach ($report['reasons'] as $key => $count) fwrite(STDOUT, "REASON {$key} {$count}\n");
foreach ($report['companies'] as $key => $count) fwrite(STDOUT, "COMPANIES {$key} {$count}\n");
foreach ($report['blockers'] as $key => $count) fwrite(STDOUT, "BLOCKER {$key} {$count}\n");
foreach ($report['detail'] as $line) fwrite(STDOUT, "DETAIL {$line}\n");
