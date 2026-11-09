<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance\Company;

use Arasya\Operations\Finance\FinanceStore;
use Arasya\Operations\Support\Uuid;
use PDO;

/**
 * Review flags: data-quality indicators for a human reviewer, stored beside the case and independent of its workflow
 * status. A flag never changes a case status, company status, risk colour, limit or balance, and it says nothing about
 * any employee. Only the passive-claim flag blocks submission, because that statement must be independently checked.
 */
final readonly class CaseFlags
{
    public const PASSIVE_CLAIM = 'EMPLOYEE_REPORTED_PASSIVE_UNVERIFIED';
    public const CANCELLED_LOOKING = 'CANCELLED_LOOKING_NAME';
    public const PLACEHOLDER_NAME = 'PLACEHOLDER_NAME';
    public const PLACEHOLDER_PHONE = 'PLACEHOLDER_PHONE';
    public const MISSING_TAX = 'MISSING_TAX_IDENTIFIER';
    public const MISSING_COUNTRY = 'MISSING_COUNTRY';
    public const REPEATED_CODE = 'REPEATED_LEGACY_CODE';
    public const REPEATED_PHONE = 'REPEATED_PHONE';
    public const SHARED_IDENTIFIER = 'DIFFERENT_NAMES_SHARED_IDENTIFIER';
    public const CONFLICTING_SOURCE = 'CONFLICTING_SOURCE_DATA';
    public const IDENTITY_CHANGE = 'UNEXPLAINED_IDENTITY_CHANGE';
    public const SAME_COMPANY = 'MULTIPLE_RECORDS_SAME_COMPANY';

    public const CODES = [self::PASSIVE_CLAIM, self::CANCELLED_LOOKING, self::PLACEHOLDER_NAME, self::PLACEHOLDER_PHONE, self::MISSING_TAX, self::MISSING_COUNTRY,
        self::REPEATED_CODE, self::REPEATED_PHONE, self::SHARED_IDENTIFIER, self::CONFLICTING_SOURCE, self::IDENTITY_CHANGE, self::SAME_COMPANY];

    /** What independent review may conclude about a passive claim. UNDETERMINED keeps the case from being submitted. */
    public const PASSIVE_OUTCOMES = ['GENUINELY_INACTIVE', 'CANCELLED', 'DUPLICATE', 'RENAMED', 'STILL_OWING_OR_RECEIVING', 'ACTIVE', 'UNDETERMINED'];
    public const REVIEW_OUTCOMES = ['EXPLAINED', 'NOT_AN_ISSUE', 'CORRECTED'];

    /** Queue order only. Not a risk colour and not a finding. */
    private const PRIORITY = [self::PASSIVE_CLAIM => 'P0', self::CANCELLED_LOOKING => 'P0', self::PLACEHOLDER_NAME => 'P0', self::CONFLICTING_SOURCE => 'P0', self::IDENTITY_CHANGE => 'P0',
        self::REPEATED_CODE => 'P1', self::REPEATED_PHONE => 'P1', self::SHARED_IDENTIFIER => 'P1', self::SAME_COMPANY => 'P1'];

    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string, mixed>|null $details codes only, never field values */
    public function raise(string $caseId, string $code, string $origin, ?array $details, string $now, bool $reopen = false): void
    {
        // A new occurrence of an event-based indicator reopens it; the earlier outcome stays in the activity trail.
        $update = $reopen ? "status = 'open', resolution = NULL, resolution_note = NULL, resolved_by_employee_uuid = NULL, resolved_at = NULL, details_json = VALUES(details_json), raised_at = VALUES(raised_at)"
            : "details_json = IF(status = 'open', VALUES(details_json), details_json)";
        $this->pdo->prepare("INSERT INTO finance_case_flags (flag_uuid, case_uuid, flag_code, status, origin, details_json, raised_at) VALUES (?, ?, ?, 'open', ?, ?, ?) ON DUPLICATE KEY UPDATE {$update}")
            ->execute([Uuid::v4(), $caseId, $code, $origin, $details === null ? null : FinanceStore::json($details), $now]);
        $this->refreshPriority($caseId);
    }

    /** Priority follows the strongest open indicator and is metadata: it does not change the case version. */
    public function refreshPriority(string $caseId): void
    {
        $s = $this->pdo->prepare("SELECT flag_code FROM finance_case_flags WHERE case_uuid = ? AND status = 'open'");
        $s->execute([$caseId]);
        $best = 'P2';
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $code) {
            $priority = self::PRIORITY[(string) $code] ?? 'P2';
            if ($priority < $best) {
                $best = $priority;
            }
        }
        $this->pdo->prepare('UPDATE finance_company_cases SET review_priority = ? WHERE case_uuid = ? AND review_priority <> ?')->execute([$best, $caseId, $best]);
    }

    /** Raises the cross-record indicators on this case and on every other case sharing the identifier. */
    public function detectShared(string $caseId, string $now): void
    {
        $s = $this->pdo->prepare('SELECT case_uuid, name_key, phone_key, legacy_customer_code, country_code, tax_normalized FROM finance_company_cases WHERE case_uuid = ?');
        $s->execute([$caseId]);
        $case = $s->fetch(PDO::FETCH_ASSOC);
        if (!$case) {
            return;
        }
        if ($case['legacy_customer_code'] !== null) {
            $this->shared($case, 'legacy_customer_code = ?', [$case['legacy_customer_code']], self::REPEATED_CODE, 'legacy_code', $now);
        }
        if ($case['phone_key'] !== null) {
            $this->shared($case, 'phone_key = ?', [$case['phone_key']], self::REPEATED_PHONE, 'phone', $now);
        }
        if ($case['tax_normalized'] !== null && $case['country_code'] !== null) {
            $this->shared($case, 'country_code = ? AND tax_normalized = ?', [$case['country_code'], $case['tax_normalized']], null, 'tax_id', $now);
        }
    }

    /** Raises MULTIPLE_RECORDS_SAME_COMPANY on every case linked to the canonical company when more than one is. */
    public function detectSameCompany(string $companyId, string $now): void
    {
        $s = $this->pdo->prepare("SELECT case_uuid FROM finance_company_cases WHERE company_uuid = ? AND status <> 'REJECTED' LIMIT 50");
        $s->execute([$companyId]);
        $ids = array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN));
        if (count($ids) > 1) {
            foreach ($ids as $id) {
                $this->raise($id, self::SAME_COMPANY, 'detector', ['records' => count($ids)], $now);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public function of(string $caseId): array
    {
        $s = $this->pdo->prepare('SELECT f.*, e.display_name FROM finance_case_flags f LEFT JOIN employees e ON e.employee_uuid = f.resolved_by_employee_uuid WHERE f.case_uuid = ? ORDER BY f.status = \'open\' DESC, f.raised_at, f.flag_code');
        $s->execute([$caseId]);
        return array_map(static fn (array $f): array => ['id' => $f['flag_uuid'], 'code' => $f['flag_code'], 'status' => $f['status'], 'origin' => $f['origin'],
            'details' => $f['details_json'] === null ? null : json_decode((string) $f['details_json'], true), 'raisedAt' => FinanceStore::iso($f['raised_at']), 'resolution' => $f['resolution'],
            'note' => $f['resolution_note'], 'resolvedBy' => $f['display_name'], 'resolvedAt' => FinanceStore::iso($f['resolved_at'])], $s->fetchAll(PDO::FETCH_ASSOC));
    }

    /** A passive claim blocks submission until independent review reached a conclusion. */
    public function hasUnresolvedPassiveClaim(string $caseId): bool
    {
        $s = $this->pdo->prepare('SELECT status, resolution FROM finance_case_flags WHERE case_uuid = ? AND flag_code = ?');
        $s->execute([$caseId, self::PASSIVE_CLAIM]);
        $flag = $s->fetch(PDO::FETCH_ASSOC);
        return $flag && ($flag['status'] === 'open' || $flag['resolution'] === 'UNDETERMINED');
    }

    /** @param array<string, mixed> $case @param list<string> $params */
    private function shared(array $case, string $condition, array $params, ?string $flag, string $indicator, string $now): void
    {
        $s = $this->pdo->prepare("SELECT case_uuid, name_key FROM finance_company_cases WHERE {$condition} AND case_uuid <> ? AND status <> 'REJECTED' LIMIT 50");
        $s->execute([...$params, $case['case_uuid']]);
        $others = $s->fetchAll(PDO::FETCH_ASSOC);
        if ($others === []) {
            return;
        }
        $different = array_filter($others, static fn (array $o): bool => $o['name_key'] !== $case['name_key']);
        $details = ['indicator' => $indicator, 'records' => count($others) + 1];
        if ($flag !== null) {
            $this->raise((string) $case['case_uuid'], $flag, 'detector', $details, $now);
        }
        if ($different !== []) {
            $this->raise((string) $case['case_uuid'], self::SHARED_IDENTIFIER, 'detector', $details, $now);
        }
        foreach ($others as $other) {
            if ($flag !== null) {
                $this->raise((string) $other['case_uuid'], $flag, 'detector', $details, $now);
            }
            if ($other['name_key'] !== $case['name_key']) {
                $this->raise((string) $other['case_uuid'], self::SHARED_IDENTIFIER, 'detector', $details, $now);
            }
        }
    }
}
