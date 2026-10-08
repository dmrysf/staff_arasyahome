<?php

declare(strict_types=1);

namespace Arasya\Operations\Management;

use RuntimeException;

/**
 * Pure reconciliation of the canonical organisation roster with existing identities.
 *
 * Matching is exact and conservative: a roster name matches an identity only when both contain the
 * same set of name tokens after upper-casing and removing diacritics ("YEMAN MESUT" = "Mesut Yeman").
 * Similar names never match. A roster name that matches several identities is ambiguous and is
 * never applied. A roster name with no identity is reported as missing: accounts are onboarded
 * explicitly, never created from a name. Root is never a candidate.
 *
 * The plan only describes organisation membership (department, position title and additional
 * departments). Roles, applications, stages and passwords are never part of it.
 */
final class OrganizationRoster
{
    /** @param array<string, mixed> $roster */
    private function __construct(private array $roster)
    {
    }

    public static function fromFile(string $path): self
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Roster file is not readable.');
        }
        return self::fromArray(json_decode($json, true, 16, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $roster */
    public static function fromArray(array $roster): self
    {
        $departments = array_column($roster['departments'] ?? [], 'name', 'key');
        $names = [];
        foreach ($roster['people'] ?? [] as $person) {
            $key = self::nameKey((string) ($person['name'] ?? ''));
            if ($key === '' || isset($names[$key])) {
                throw new RuntimeException('Roster names must be present and unique: ' . ($person['name'] ?? ''));
            }
            $names[$key] = true;
            if (isset($person['rollout']) && $person['rollout'] !== 'excluded') {
                throw new RuntimeException('Roster rollout marker must be "excluded": ' . $person['name']);
            }
            $memberships = [$person['department'] ?? null, ...($person['additionalDepartments'] ?? [])];
            if (count(array_unique($memberships)) !== count($memberships)) {
                throw new RuntimeException('Roster person repeats a department: ' . $person['name']);
            }
            foreach ($memberships as $department) {
                if (!is_string($department) || !isset($departments[$department])) {
                    throw new RuntimeException('Roster person references an unknown department: ' . $person['name']);
                }
            }
        }
        return new self($roster);
    }

    /** Upper-case, diacritic-free, sorted name tokens. */
    public static function nameKey(string $name): string
    {
        $map = ['Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T', 'Ç' => 'C', 'Ğ' => 'G', 'İ' => 'I', 'Ö' => 'O', 'Ü' => 'U', 'Ä' => 'A', 'É' => 'E'];
        $upper = strtr(mb_strtoupper(trim($name)), $map);
        $tokens = preg_split('/[^A-Z0-9]+/', $upper, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($tokens, SORT_STRING);
        return implode(' ', $tokens);
    }

    public static function departmentKey(string $name): string
    {
        return self::nameKey($name);
    }

    /**
     * @param list<array{id: string, username: string, displayName: string, positionTitle: string|null, departmentId: int, departmentName: string, status: string, secondaryDepartmentIds: list<int>}> $identities non-root identities
     * @param list<array{id: int, name: string, status: string}> $existingDepartments
     * @return array<string, mixed>
     */
    public function plan(array $identities, array $existingDepartments): array
    {
        $departmentsByName = [];
        foreach ($existingDepartments as $department) {
            $departmentsByName[self::departmentKey($department['name'])][] = $department;
        }
        $departmentPlan = [];
        foreach ($this->roster['departments'] as $department) {
            $existing = $departmentsByName[self::departmentKey($department['name'])] ?? [];
            $departmentPlan[$department['key']] = [
                'key' => $department['key'],
                'name' => $department['name'],
                'location' => $department['location'] ?? null,
                'existingId' => count($existing) === 1 ? (int) $existing[0]['id'] : null,
                'action' => match (true) {
                    count($existing) > 1 => 'ambiguous',
                    count($existing) === 1 && $existing[0]['status'] !== 'active' => 'inactive',
                    count($existing) === 1 => 'exists',
                    default => 'create',
                },
            ];
        }
        $byName = [];
        foreach ($identities as $identity) {
            $byName[self::nameKey($identity['displayName'])][] = $identity;
        }
        $matched = [];
        $missing = [];
        $ambiguous = [];
        foreach ($this->roster['people'] as $person) {
            $candidates = $byName[self::nameKey($person['name'])] ?? [];
            if ($candidates === []) {
                $missing[] = ['name' => $person['name'], 'department' => $departmentPlan[$person['department']]['name'], 'title' => $person['title'] ?? null, 'rollout' => $person['rollout'] ?? null];
                continue;
            }
            if (count($candidates) > 1) {
                $ambiguous[] = ['name' => $person['name'], 'candidates' => array_map(static fn (array $c): array => ['id' => $c['id'], 'username' => $c['username'], 'status' => $c['status']], $candidates)];
                continue;
            }
            $identity = $candidates[0];
            $primary = $departmentPlan[$person['department']];
            $additional = array_map(static fn (string $key): array => $departmentPlan[$key], $person['additionalDepartments'] ?? []);
            $changes = [];
            if ($primary['existingId'] === null || $primary['existingId'] !== $identity['departmentId']) {
                $changes['department'] = ['before' => $identity['departmentName'], 'after' => $primary['name']];
            }
            if (isset($person['title']) && $person['title'] !== $identity['positionTitle']) {
                $changes['positionTitle'] = ['before' => $identity['positionTitle'], 'after' => $person['title']];
            }
            $wantedAdditional = array_map(static fn (array $d): string => $d['name'], $additional);
            $currentAdditional = $identity['secondaryDepartmentNames'] ?? [];
            sort($wantedAdditional);
            sort($currentAdditional);
            if ($wantedAdditional !== $currentAdditional && ($wantedAdditional !== [] || $currentAdditional !== [])) {
                $changes['additionalDepartments'] = ['before' => $currentAdditional, 'after' => $wantedAdditional];
            }
            $matched[] = [
                'name' => $person['name'],
                'employeeId' => $identity['id'],
                'username' => $identity['username'],
                'status' => $identity['status'],
                'department' => $person['department'],
                'title' => $person['title'] ?? null,
                'additionalDepartments' => $person['additionalDepartments'] ?? [],
                'changes' => $changes,
                'notes' => array_values(array_filter([
                    isset($person['principal']) ? "Root designates this person as {$person['principal']} principal in the Dashboard." : null,
                    isset($person['proposedRole']) ? "Role {$person['proposedRole']} is assigned separately by an authorised administrator." : null,
                    $identity['status'] !== 'active' ? 'The identity is inactive; membership changes do not activate it.' : null,
                    ($person['rollout'] ?? null) === 'excluded' ? 'Excluded from the current rollout: no application access is planned.' : null,
                ])),
            ];
        }
        return [
            'rosterSize' => count($this->roster['people']),
            'departments' => array_values($departmentPlan),
            'matched' => $matched,
            'missing' => $missing,
            'ambiguous' => $ambiguous,
            'summary' => [
                'matched' => count($matched),
                'missing' => count($missing),
                'ambiguous' => count($ambiguous),
                'withChanges' => count(array_filter($matched, static fn (array $m): bool => $m['changes'] !== [])),
                'departmentsToCreate' => count(array_filter($departmentPlan, static fn (array $d): bool => $d['action'] === 'create')),
            ],
        ];
    }
}
