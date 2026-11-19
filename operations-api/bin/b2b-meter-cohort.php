<?php
declare(strict_types=1);
/**
 * B2B test cohort for the meter reports (API 2.76.0, migration 057). The orders themselves are never changed or deleted:
 * a cohort row keeps a commercial order out of B2B sold meters, and its canonical production order (when handed off) out
 * of every production metric (production_meter_exclusions). Dry run by default; `--apply` writes the rows and one IAM
 * audit event per order in one transaction.
 *
 *   php bin/b2b-meter-cohort.php --snapshot --cohort=<key> --by="<name>" [--note="..."] [--expect=<sha256>] [--apply]
 *        Every B2B order that exists now (owner decision 2026-11-19: all existing B2B orders are tests). The dry run prints
 *        the inventory and its sha256; `--apply` requires `--expect=<that sha256>`, so exactly the reviewed set is recorded.
 *        Orders created later are never included by a snapshot.
 *   php bin/b2b-meter-cohort.php --order=<B2B-ORD-…> --cohort=<key> --by="<name>" [--note="..."] [--apply]
 *        One later, separate test decision.
 *   php bin/b2b-meter-cohort.php --list
 *        The cohort with counts and entered meters (the audit report).
 *
 * After `--apply`, run bin/meter-ledger.php (or wait for the automatic run): it reverses nothing that was never booked and
 * books nothing for cohort orders.
 */
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Intelligence\B2bSoldMeters;
use Arasya\Operations\Intelligence\MeterContract;

$container = require __DIR__ . '/cli-bootstrap.php';
try {
    $pdo = $container->pdo();
    if (!B2bSoldMeters::available($pdo)) {
        throw new RuntimeException('Migration 057 is not applied');
    }
    $o = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (in_array($argument, ['--apply', '--list', '--snapshot'], true)) { $o[substr($argument, 2)] = true; continue; }
        if (preg_match('/^--(order|cohort|by|note|expect)=(.+)$/D', $argument, $m) === 1) { $o[$m[1]] = $m[2]; continue; }
        throw new InvalidArgumentException('Unknown argument');
    }
    if (isset($o['list'])) {
        $rows = $pdo->query('SELECT c.cohort_key, c.order_code_snapshot, c.status_snapshot, c.entered_meters_snapshot, c.canonical_order_uuid_snapshot, b.status AS status_now,
                (SELECT o.global_order_id FROM operational_orders o WHERE o.source_key = \'b2b\' AND o.source_order_id = c.b2b_order_uuid) AS canonical_now,
                (SELECT COUNT(*) FROM production_meter_exclusions x JOIN operational_orders o ON o.order_uuid = x.order_uuid WHERE o.source_key = \'b2b\' AND o.source_order_id = c.b2b_order_uuid) AS canonical_excluded,
                (SELECT COALESCE(SUM(l.delta_meters), 0) FROM b2b_sold_meter_ledger l WHERE l.b2b_order_uuid = c.b2b_order_uuid) AS ledger_net,
                c.decided_by_label, c.decided_at FROM b2b_meter_test_cohort c JOIN b2b_orders b ON b.order_uuid = c.b2b_order_uuid ORDER BY c.decided_at, c.order_code_snapshot')->fetchAll(PDO::FETCH_ASSOC);
        $units = 0; $canonical = 0;
        foreach ($rows as $r) {
            $units += MeterContract::units($r['entered_meters_snapshot']) ?? 0;
            $canonical += $r['canonical_now'] !== null ? 1 : 0;
            fwrite(STDOUT, sprintf("%s %s %s->%s entered=%s m canonical=%s excluded=%s ledger_net=%s by %s at %sZ\n", $r['cohort_key'], $r['order_code_snapshot'], $r['status_snapshot'], $r['status_now'],
                $r['entered_meters_snapshot'], $r['canonical_now'] ?? '-', $r['canonical_now'] === null ? '-' : ((int) $r['canonical_excluded'] === 1 ? 'yes' : 'NO'), $r['ledger_net'], $r['decided_by_label'], $r['decided_at']));
        }
        fwrite(STDOUT, sprintf("COHORT: orders=%d canonical=%d entered_meters=%s m\n", count($rows), $canonical, MeterContract::meters($units)));
        exit(0);
    }
    foreach (['cohort', 'by'] as $required) {
        if (!isset($o[$required]) || !is_string($o[$required])) throw new InvalidArgumentException("--{$required} is required");
    }
    if (preg_match('/^[a-z0-9][a-z0-9-]{2,59}$/D', $o['cohort']) !== 1) throw new InvalidArgumentException('--cohort must be 3-60 lowercase letters, digits or dashes');
    if (mb_strlen($o['by']) > 160 || mb_strlen($o['note'] ?? '') > 300) throw new InvalidArgumentException('--by or --note too long');
    if (isset($o['snapshot']) === isset($o['order'])) throw new InvalidArgumentException('Use exactly one of --snapshot or --order');

    $sql = "SELECT b.order_uuid, b.order_code, b.status, b.created_at,
            (SELECT COALESCE(SUM(CASE WHEN l.item_type <> 'other' AND l.meters > 0 THEN l.meters ELSE 0 END), 0) FROM b2b_order_lines l WHERE l.order_uuid = b.order_uuid) AS entered_meters,
            (SELECT o.order_uuid FROM operational_orders o WHERE o.source_key = 'b2b' AND o.source_order_id = b.order_uuid) AS canonical_uuid,
            (SELECT c.cohort_key FROM b2b_meter_test_cohort c WHERE c.b2b_order_uuid = b.order_uuid) AS cohort_now
        FROM b2b_orders b";
    $s = isset($o['order']) ? $pdo->prepare($sql . ' WHERE b.order_code = ?') : $pdo->prepare($sql . ' ORDER BY b.order_number');
    $s->execute(isset($o['order']) ? [$o['order']] : []);
    $orders = $s->fetchAll(PDO::FETCH_ASSOC);
    if ($orders === []) throw new InvalidArgumentException('No B2B order found');
    $lines = array_map(static fn (array $r): string => implode('|', [$r['order_uuid'], $r['order_code'], $r['status'], MeterContract::meters(MeterContract::units($r['entered_meters']) ?? 0), $r['canonical_uuid'] ?? '-']), $orders);
    $sha = hash('sha256', implode("\n", $lines));
    $total = array_sum(array_map(static fn (array $r): int => MeterContract::units($r['entered_meters']) ?? 0, $orders));
    foreach ($orders as $r) {
        fwrite(STDOUT, sprintf("%s %s %s entered=%s m canonical=%s%s\n", $r['order_code'], substr($r['order_uuid'], 0, 8), $r['status'], MeterContract::meters(MeterContract::units($r['entered_meters']) ?? 0),
            $r['canonical_uuid'] !== null ? substr($r['canonical_uuid'], 0, 8) : '-', $r['cohort_now'] !== null ? " (already in {$r['cohort_now']})" : ''));
    }
    fwrite(STDOUT, sprintf("INVENTORY: orders=%d canonical=%d entered_meters=%s m sha256=%s\n", count($orders), count(array_filter($orders, static fn ($r) => $r['canonical_uuid'] !== null)), MeterContract::meters($total), $sha));
    if (!isset($o['apply'])) {
        fwrite(STDOUT, "DRY RUN (nothing written).\n");
        exit(0);
    }
    if (isset($o['snapshot']) && ($o['expect'] ?? '') !== $sha) {
        throw new InvalidArgumentException('--apply with --snapshot needs --expect=<sha256 of the reviewed dry run>; the inventory changed or was not reviewed');
    }
    $now = $container->clock()->now()->format('Y-m-d H:i:s.u');
    $request = 'cli-cohort-' . bin2hex(random_bytes(6));
    $audit = new IamAuditLogger($pdo);
    $pdo->beginTransaction();
    $insert = $pdo->prepare("INSERT IGNORE INTO b2b_meter_test_cohort (b2b_order_uuid, cohort_key, reason, order_code_snapshot, status_snapshot, entered_meters_snapshot, canonical_order_uuid_snapshot,
        snapshot_sha256, note, decided_by_label, decided_at) VALUES (?, ?, 'test_order', ?, ?, ?, ?, ?, ?, ?, ?)");
    $recorded = 0;
    foreach ($orders as $r) {
        $insert->execute([$r['order_uuid'], $o['cohort'], $r['order_code'], $r['status'], MeterContract::meters(MeterContract::units($r['entered_meters']) ?? 0), $r['canonical_uuid'], $sha, $o['note'] ?? null, $o['by'], $now]);
        if ($insert->rowCount() === 1) {
            $recorded++;
            $audit->record(null, 'production_intelligence.b2b_test_order_recorded', 'b2b_order', (string) $r['order_uuid'], (string) $r['order_code'],
                ['cohort' => $o['cohort'], 'decidedBy' => $o['by'], 'status' => $r['status'], 'enteredMeters' => MeterContract::meters(MeterContract::units($r['entered_meters']) ?? 0),
                    'canonical' => $r['canonical_uuid'], 'snapshotSha256' => $sha], $request, $now);
        }
    }
    $excluded = B2bSoldMeters::excludeCohortCanonicals($pdo, $now, false);
    $pdo->commit();
    fwrite(STDOUT, sprintf("RECORDED: %d new cohort rows (%d already present), %d canonical orders excluded from production metrics. Orders unchanged; run bin/meter-ledger.php.\n",
        $recorded, count($orders) - $recorded, count($excluded)));
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'B2B meter cohort failed (' . get_class($error) . '): ' . ($error instanceof InvalidArgumentException || $error instanceof RuntimeException ? rtrim($error->getMessage(), '.') : 'see logs') . ". No secrets are printed.\n");
    exit(1);
}
