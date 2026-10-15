<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

/**
 * The one server-side route evaluator for physical pieces (API 2.32.0, version 2 in API 2.34.0). Normal production,
 * rework replay, stage queues, the packing gate and every view ask this class which canonical stages a product type goes
 * through and what each stage requires; no client and no other service decides it.
 *
 * Policy version 1 (piece_routing):
 *   draperii   every canonical stage: 2 -> 3 -> 4 -> 5 -> 6 -> 7 -> 8 -> ... (manufacturing at every stage)
 *   perdea     bottom-hem (6) and side-hem (7) are not applicable: 2 -> 3 -> 4 -> 5 -> 8 -> ...
 *   other      every canonical stage (no exemption is known for it)
 *   unknown    every canonical stage (the safe existing route) and the classification needs confirmation
 *
 * Policy version 2 ($assisted, feature perdea_assisted_checkpoints): identical, except that a PERDEA piece goes through
 * bottom-hem and side-hem with the requirement assisted_checkpoint instead of skipping them. A helper may do the work
 * and the stage employee verifies every PERDEA piece separately per stage and production cycle (AssistedCheckpoints).
 * The verification is not manufacturing: no stage completion is logged for the piece and nobody earns piece work for it.
 * Every type then shares one route, so no carrier splits by product type any more.
 *
 * The 14 canonical stage ids and ordinals never change; a route only marks what a stage requires. The exemption and the
 * checkpoint apply only to the canonical workflow they were defined for (curtain-production v1); any other workflow keeps
 * manufacturing at every stage. Pure: no database access.
 */
final class ProductRoutePolicy
{
    public const VERSION = 1;
    /** Version recorded when the assisted PERDEA checkpoints apply. */
    public const VERSION_ASSISTED = 2;
    public const PERDEA = 'perdea';
    public const DRAPERII = 'draperii';
    public const OTHER = 'other';
    public const UNKNOWN = 'unknown';
    public const TYPES = [self::PERDEA, self::DRAPERII, self::OTHER, self::UNKNOWN];
    /** Stages whose manufacturing operations a PERDEA does not have. */
    public const PERDEA_NOT_APPLICABLE = ['bottom-hem', 'side-hem'];
    /** Stages where policy version 2 requires a verified assisted checkpoint of every PERDEA piece. */
    public const ASSISTED_STAGES = self::PERDEA_NOT_APPLICABLE;
    public const MANUFACTURING = 'manufacturing';
    public const ASSISTED_CHECKPOINT = 'assisted_checkpoint';
    public const NOT_APPLICABLE = 'not_applicable';

    private function __construct()
    {
    }

    /** What the stage requires of a piece of this type: manufacturing, assisted_checkpoint or not_applicable. */
    public static function requirement(ProductionWorkflow $workflow, string $type, string $stageId, bool $assisted = false): string
    {
        if ($type !== self::PERDEA || !self::canonical($workflow) || !in_array($stageId, self::PERDEA_NOT_APPLICABLE, true)) {
            return self::MANUFACTURING;
        }
        return $assisted ? self::ASSISTED_CHECKPOINT : self::NOT_APPLICABLE;
    }

    /** Whether the stage applies to the product type in this workflow (manufacturing or an assisted checkpoint). */
    public static function applies(ProductionWorkflow $workflow, string $type, string $stageId, bool $assisted = false): bool
    {
        return self::requirement($workflow, $type, $stageId, $assisted) !== self::NOT_APPLICABLE;
    }

    /** Whether $stageId is a stage where policy version 2 can require an assisted checkpoint in this workflow. */
    public static function assistedStage(ProductionWorkflow $workflow, string $stageId): bool
    {
        return self::canonical($workflow) && in_array($stageId, self::ASSISTED_STAGES, true);
    }

    public static function version(bool $assisted): int
    {
        return $assisted ? self::VERSION_ASSISTED : self::VERSION;
    }

    /** Applicable stage ids of a product type, in canonical order. @return list<string> */
    public static function route(ProductionWorkflow $workflow, string $type, bool $assisted = false): array
    {
        $route = [];
        foreach ($workflow->stages as $stage) {
            if (self::applies($workflow, $type, $stage->id, $assisted)) {
                $route[] = $stage->id;
            }
        }
        return $route;
    }

    /**
     * The next applicable stage after $current for a product type, and the canonical stages in between that do not apply.
     * next null: $current is the last stage (production completes).
     *
     * @return array{next: ?ProductionStage, skipped: list<string>}
     */
    public static function next(ProductionWorkflow $workflow, string $current, string $type, bool $assisted = false): array
    {
        $found = false;
        $skipped = [];
        foreach ($workflow->stages as $stage) {
            if (!$found) {
                $found = $stage->id === $current;
                continue;
            }
            if (self::applies($workflow, $type, $stage->id, $assisted)) {
                return ['next' => $stage, 'skipped' => $skipped];
            }
            $skipped[] = $stage->id;
        }
        if (!$found) {
            throw new \Arasya\Operations\Http\ApiException(409, 'SOURCE_STAGE_UNKNOWN', 'The stage is not part of the active production workflow.');
        }
        return ['next' => null, 'skipped' => $skipped];
    }

    /**
     * The next stage for a carrier holding pieces of several types: one entry per distinct next stage, each with the
     * types that go there and the stages they skip. One entry means the carrier moves as a whole.
     *
     * @param list<string> $types @return array<string, array{next: ?ProductionStage, skipped: list<string>, types: list<string>}> keyed by next stage id ('' = complete)
     */
    public static function groups(ProductionWorkflow $workflow, string $current, array $types, bool $assisted = false): array
    {
        $groups = [];
        foreach (array_values(array_unique($types)) as $type) {
            $step = self::next($workflow, $current, $type, $assisted);
            $key = $step['next']?->id ?? '';
            $groups[$key] ??= ['next' => $step['next'], 'skipped' => $step['skipped'], 'types' => []];
            $groups[$key]['types'][] = $type;
        }
        // The longest route first (the earliest next stage): that group keeps the carrier.
        uasort($groups, static fn (array $a, array $b): int => ($a['next']?->ordinal ?? PHP_INT_MAX) <=> ($b['next']?->ordinal ?? PHP_INT_MAX));
        return $groups;
    }

    /** Normalizes a source production classification (B2B kind, Arasya/Trendyol preparation kind) to a route type. */
    public static function fromSourceKind(mixed $kind): string
    {
        return match ($kind) {
            'curtain' => self::PERDEA,
            'drapery' => self::DRAPERII,
            'other' => self::OTHER,
            default => self::UNKNOWN,
        };
    }

    public static function canonical(ProductionWorkflow $workflow): bool
    {
        return $workflow->id === CanonicalProductionWorkflowContract::WORKFLOW_ID && $workflow->version === CanonicalProductionWorkflowContract::VERSION;
    }
}
