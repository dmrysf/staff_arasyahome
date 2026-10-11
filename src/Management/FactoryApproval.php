<?php
declare(strict_types=1);

namespace Arasya\Operations\Management;

use DateTimeImmutable;
use RuntimeException;

/** Operator records the owner's separate approval after reviewing this exact group dry-run. No approval default. */
final readonly class FactoryApproval
{
    private function __construct(public string $group, public string $planSha256, public string $reference, public string $approvedBy, private DateTimeImmutable $approvedAt, private DateTimeImmutable $expiresAt) {}

    public static function fromFile(string $path, string $group, string $expectedPlan, DateTimeImmutable $now, array $forbiddenRoots): self
    {
        $real = realpath($path);
        if ($real === false || is_link($path) || !is_file($path) || (fileperms($real) & 077) !== 0 || (fileperms(dirname($real)) & 077) !== 0 || filesize($real) > 4096) throw new RuntimeException('FACTORY_APPROVAL_FILE_NOT_PRIVATE');
        foreach ($forbiddenRoots as $root) {
            $forbidden = realpath($root);
            if ($forbidden !== false && ($real === $forbidden || str_starts_with($real, rtrim($forbidden, '/') . '/'))) throw new RuntimeException('FACTORY_APPROVAL_FILE_LOCATION_FORBIDDEN');
        }
        return self::fromArray(json_decode((string) file_get_contents($real), true, 8, JSON_THROW_ON_ERROR), $group, $expectedPlan, $now);
    }

    public static function fromArray(array $approval, string $group, string $expectedPlan, DateTimeImmutable $now): self
    {
        $fields = ['group', 'planSha256', 'reference', 'approvedBy', 'approvedAt', 'expiresAt'];
        if (array_diff(array_keys($approval), $fields) !== [] || array_diff($fields, array_keys($approval)) !== [] || !isset(FactoryOnboardingPlan::STAGES[$group]) || ($approval['group'] ?? null) !== $group || !preg_match('/^[a-f0-9]{64}$/D', $expectedPlan) || ($approval['planSha256'] ?? null) !== $expectedPlan) throw new RuntimeException('FACTORY_APPROVAL_MISMATCH');
        foreach ($fields as $field) if (!is_string($approval[$field]) || trim($approval[$field]) === '' || strlen($approval[$field]) > 200) throw new RuntimeException('FACTORY_APPROVAL_INVALID');
        $dates = [];
        foreach (['approvedAt', 'expiresAt'] as $field) {
            $date = DateTimeImmutable::createFromFormat('!' . DATE_ATOM, $approval[$field]);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) throw new RuntimeException('FACTORY_APPROVAL_DATE_INVALID');
            $dates[$field] = $date;
        }
        if ($dates['approvedAt'] > $now || $dates['expiresAt'] <= $now || $dates['expiresAt'] <= $dates['approvedAt'] || $dates['expiresAt']->getTimestamp() - $dates['approvedAt']->getTimestamp() > 86400) throw new RuntimeException('FACTORY_APPROVAL_EXPIRED');
        return new self($group, $expectedPlan, $approval['reference'], $approval['approvedBy'], $dates['approvedAt'], $dates['expiresAt']);
    }

    public function requireCurrent(DateTimeImmutable $now): void
    {
        if ($this->approvedAt > $now || $this->expiresAt <= $now) throw new RuntimeException('FACTORY_APPROVAL_EXPIRED');
    }
}
