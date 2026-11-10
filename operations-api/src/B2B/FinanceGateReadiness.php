<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Read-only readiness report for the owner decisions D2 (enforce) and D3 (orders finalized before activation): what an
 * `enforce` gate would decide now for every open B2B order, and which active companies would pass a finalization at all.
 * It writes nothing (the caller runs it inside a READ ONLY transaction), journals nothing and never changes a mode.
 * Output holds counts, reason codes and internal company and order codes only: no amount, no name, no contact.
 */
final class FinanceGateReadiness
{
    /**
     * @param FinanceGate $enforce a gate built with mode and release mode `enforce`
     * @param Closure(string): array<string, mixed> $profile company id => Finance credit profile
     * @return array{orders: array<string, int>, reasons: array<string, int>, companies: array<string, int>, blockers: array<string, int>, detail: list<string>}
     */
    public static function report(PDO $pdo, FinanceGate $enforce, Closure $profile, bool $detail = false): array
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $orders = [];
        $reasons = [];
        $lines = [];
        $s = $pdo->query("SELECT o.*, c.company_code FROM b2b_orders o JOIN b2b_companies c ON c.company_uuid = o.company_uuid
            WHERE o.status = 'draft' OR (o.status = 'finalized' AND NOT EXISTS (SELECT 1 FROM b2b_production_handoffs h WHERE h.b2b_order_uuid = o.order_uuid))
            ORDER BY o.order_code");
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decision = $row['status'] === 'draft' ? $enforce->decide((string) $row['company_uuid'], $row, $now) : $enforce->recheck((string) $row['company_uuid'], $row, $now);
            $key = $decision['phase'] . '/' . $decision['outcome'] . (($decision['grandfathered'] ?? false) ? '/grandfathered' : '');
            $orders[$key] = ($orders[$key] ?? 0) + 1;
            foreach ((array) ($decision['reasons'] ?? []) as $reason) {
                $reasons[$decision['phase'] . '/' . $reason] = ($reasons[$decision['phase'] . '/' . $reason] ?? 0) + 1;
            }
            if ($detail && $decision['outcome'] !== 'ALLOWED') {
                $lines[] = $row['order_code'] . ' ' . $row['company_code'] . ' ' . $decision['phase'] . ' ' . $decision['outcome'] . ' ' . implode(',', (array) ($decision['reasons'] ?? []));
            }
        }
        $companies = ['active' => 0, 'ready_for_credit' => 0];
        $blockers = [];
        foreach ($pdo->query("SELECT company_uuid, company_code FROM b2b_companies WHERE status = 'active' ORDER BY company_code")->fetchAll(PDO::FETCH_ASSOC) as $company) {
            $companies['active']++;
            $found = array_values((array) ($profile((string) $company['company_uuid'])['eligibilityBlockers'] ?? []));
            if ($found === []) {
                $companies['ready_for_credit']++;
            }
            foreach ($found as $blocker) {
                $blockers[$blocker] = ($blockers[$blocker] ?? 0) + 1;
            }
            if ($detail && $found !== []) {
                $lines[] = $company['company_code'] . ' company ' . implode(',', $found);
            }
        }
        ksort($orders);
        ksort($reasons);
        ksort($blockers);
        return ['orders' => $orders, 'reasons' => $reasons, 'companies' => $companies, 'blockers' => $blockers, 'detail' => $lines];
    }
}
