<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance\Company;

use Arasya\Operations\Http\ApiException;

/**
 * The company verification state machine. Every status change goes through allow(), so an impossible transition
 * (for example NEEDS_REVIEW -> APPROVED) fails on the server regardless of what a client sends.
 */
final class CaseWorkflow
{
    public const OPEN_QUEUE = ['IMPORTED', 'NEEDS_REVIEW', 'NEEDS_INFORMATION', 'SUSPECTED_DUPLICATE', 'REOPENED'];

    /** @var array<string, list<string>> */
    public const ALLOWED = [
        'IMPORTED' => ['NEEDS_REVIEW', 'NEEDS_INFORMATION', 'SUSPECTED_DUPLICATE', 'IN_REVIEW', 'REJECTED'],
        'NEEDS_REVIEW' => ['IN_REVIEW', 'REJECTED'],
        'NEEDS_INFORMATION' => ['IN_REVIEW', 'REJECTED'],
        'SUSPECTED_DUPLICATE' => ['IN_REVIEW', 'REJECTED'],
        'REOPENED' => ['IN_REVIEW', 'REJECTED'],
        'IN_REVIEW' => ['NEEDS_REVIEW', 'NEEDS_INFORMATION', 'SUSPECTED_DUPLICATE', 'READY_FOR_SECOND_CHECK', 'REJECTED'],
        'READY_FOR_SECOND_CHECK' => ['APPROVED', 'NEEDS_INFORMATION', 'REJECTED'],
        'APPROVED' => ['REOPENED'],
        'REJECTED' => ['REOPENED'],
    ];

    /** Checklist items; tax_id may stay "pending" (external verification outstanding), contact and documents may be "na". */
    public const CHECKLIST = ['legal_name', 'country', 'tax_id', 'duplicate_search', 'address', 'contact', 'sources', 'documents', 'conflicts'];
    public const CHECKLIST_STATES = ['open', 'done', 'pending', 'na'];

    /** Which checklist items an edited field reopens. */
    public const FIELD_ITEMS = [
        'legalName' => ['legal_name', 'duplicate_search'], 'commercialName' => ['duplicate_search'], 'countryCode' => ['country', 'tax_id', 'duplicate_search'],
        'taxIdentifier' => ['tax_id', 'duplicate_search'], 'vatNumber' => ['tax_id', 'duplicate_search'], 'registrationNumber' => ['tax_id', 'duplicate_search'],
        'addressLine' => ['address'], 'city' => ['address'], 'postalCode' => ['address'], 'countyRegion' => ['address'],
        'contactName' => ['contact'], 'phone' => ['contact', 'duplicate_search'], 'email' => ['contact'], 'website' => ['contact'],
        'legacyCustomerCode' => ['sources', 'duplicate_search'], 'notes' => [],
    ];

    public static function allow(string $from, string $to): void
    {
        if (!in_array($to, self::ALLOWED[$from] ?? [], true)) {
            throw new ApiException(409, 'INVALID_TRANSITION', 'This status change is not allowed.', ['from' => $from, 'to' => $to]);
        }
    }

    /** @return array<string, array{state: string}> */
    public static function emptyChecklist(): array
    {
        $out = [];
        foreach (self::CHECKLIST as $item) {
            $out[$item] = ['state' => 'open'];
        }
        return $out;
    }

    /** Items not yet in an acceptable final state. @param array<string, array<string, mixed>> $checklist @return list<string> */
    public static function incomplete(array $checklist): array
    {
        $out = [];
        foreach (self::CHECKLIST as $item) {
            $state = $checklist[$item]['state'] ?? 'open';
            $ok = $state === 'done'
                || ($item === 'tax_id' && $state === 'pending')
                || (in_array($item, ['contact', 'documents', 'address'], true) && $state === 'na');
            if (!$ok) {
                $out[] = $item;
            }
        }
        return $out;
    }
}
