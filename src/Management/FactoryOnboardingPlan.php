<?php
declare(strict_types=1);

namespace Arasya\Operations\Management;

use RuntimeException;

/** Immutable owner matrix. Organisation describes people; only the fixed matrix authorizes factory work. */
final readonly class FactoryOnboardingPlan
{
    public const SOURCES = ['trendhome', 'outletperdele', 'trendyol'];
    public const STAGES = [
        'cutting' => ['material-preparation'],
        'workshop' => ['workshop-receiving', 'labeling', 'material-straightening', 'bottom-hem', 'side-hem', 'ironing', 'height', 'header-tape', 'sewing-finishing', 'quality-control'],
        'packing' => ['packing'],
        'internal-handoff' => ['delivery'],
    ];
    private const DEPARTMENTS = ['taiere' => 'cutting', 'croitorie' => 'workshop', 'croitorie-dragon' => 'workshop', 'depozit' => 'packing', 'logistica-distributie' => 'internal-handoff', 'expediere-curierat' => 'internal-handoff'];
    private const COUNTS = ['cutting' => 4, 'workshop' => 15, 'packing' => 4, 'internal-handoff' => 6];

    /** @param array<string, list<array<string,mixed>>> $members */
    private function __construct(private array $members) {}

    public static function fromFiles(string $planPath, string $rosterPath): self
    {
        return self::fromArrays(json_decode((string) file_get_contents($planPath), true, 32, JSON_THROW_ON_ERROR), json_decode((string) file_get_contents($rosterPath), true, 32, JSON_THROW_ON_ERROR));
    }

    public static function fromArrays(array $plan, array $roster): self
    {
        OrganizationRoster::fromArray($roster);
        if (array_diff(array_keys($plan), ['version', 'description', 'sources', 'groups']) !== [] || ($plan['version'] ?? null) !== 1 || ($plan['sources'] ?? null) !== self::SOURCES || !is_array($plan['groups'] ?? null) || array_keys($plan['groups']) !== array_keys(self::STAGES)) {
            throw new RuntimeException('FACTORY_MATRIX_INVALID');
        }
        $expected = [];
        foreach ($roster['people'] as $person) {
            $group = self::DEPARTMENTS[$person['department']] ?? null;
            if ($group !== null) {
                if (($person['rollout'] ?? null) === 'excluded') throw new RuntimeException('FACTORY_EXCLUDED_PERSON');
                $expected[$person['name']] = ['departmentKey' => $person['department'], 'group' => $group];
            }
        }
        $members = []; $seen = [];
        foreach (self::STAGES as $group => $stages) {
            $entry = $plan['groups'][$group];
            if (!is_array($entry) || array_diff(array_keys($entry), ['label', 'names', 'stageIds']) !== [] || ($entry['stageIds'] ?? null) !== $stages || !is_array($entry['names'] ?? null) || !array_is_list($entry['names']) || count($entry['names']) !== self::COUNTS[$group]) {
                throw new RuntimeException('FACTORY_GROUP_INVALID');
            }
            foreach ($entry['names'] as $name) {
                if (!is_string($name) || isset($seen[$name]) || ($expected[$name]['group'] ?? null) !== $group) throw new RuntimeException('FACTORY_PERSON_INVALID');
                $seen[$name] = true;
                $members[$group][] = ['name' => $name, 'username' => OrganizationOnboarding::username($name), 'departmentKey' => $expected[$name]['departmentKey'], 'group' => $group, 'stageIds' => $stages, 'stageScopes' => array_fill_keys($stages, self::SOURCES)];
            }
        }
        if (count($seen) !== 29 || array_diff(array_keys($expected), array_keys($seen)) !== []) throw new RuntimeException('FACTORY_MEMBERSHIP_CHANGED');
        return new self($members);
    }

    public function people(?string $group = null): array
    {
        if ($group !== null && !isset($this->members[$group])) throw new RuntimeException('FACTORY_GROUP_UNKNOWN');
        return $group === null ? array_merge(...array_values($this->members)) : $this->members[$group];
    }
}
