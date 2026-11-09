<?php

declare(strict_types=1);

use Arasya\Operations\Config\ConfigLoader;
use Arasya\Operations\Config\FinanceConfigGuard;

// Finance configuration check (API 2.63.0). Needs no database. The deployment runs it before activation and refuses the
// release when it fails. Output names keys and states only, never a configured value. Exit code 0 = pass, 1 = refuse.
// See docs/finance-v0.4.0.md, section 9.
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}
require dirname(__DIR__) . '/bootstrap.php';

try {
    $values = (new ConfigLoader(null, dirname(__DIR__)))->load();
} catch (Throwable) {
    fwrite(STDOUT, "FAIL finance_config_unreadable\n");
    exit(1);
}

$result = FinanceConfigGuard::inspect($values);
foreach ($result['checks'] as $line) {
    fwrite(STDOUT, "{$line['state']} {$line['check']}\n");
}
if (!$result['defaults']) {
    // A hash of the reviewed values, not the values. Record it as ARASYA_FINANCE_CONFIG_ACK only after an authorized review.
    fwrite(STDOUT, "INFO finance_config_fingerprint {$result['fingerprint']}\n");
}
exit($result['ok'] ? 0 : 1);
