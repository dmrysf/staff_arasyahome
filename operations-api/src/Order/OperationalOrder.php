<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

use DateTimeImmutable;

final readonly class OperationalOrder
{
    /** @param list<OperationalOrderItem> $items */
    public function __construct(
        public string $orderUuid,
        public GlobalOrderId $globalId,
        public string $orderNumber,
        public string $productionStageId,
        public ?string $sourceCommerceStatusCode,
        public ?string $sourceCommerceStatusLabel,
        public ?string $productionNotes,
        public string $operationalStatus, // source availability: 'in_progress', 'handed_over', 'unavailable'
        public OrderFreshness $freshness,
        public int $version,
        public ?DateTimeImmutable $acceptedAt,
        public DateTimeImmutable $updatedAt,
        public array $items = [],
        public ?EmployeeOrderRelation $relation = null,
        public int $productionVersion = 1,
        public ?string $productionOwnerEmployeeUuid = null,
        public ?DateTimeImmutable $productionCompletedAt = null,
        public ?array $productionContext = null,
        public ?string $openExceptionUuid = null,
        public string $documentStatus = 'none',
        /** 'source' (still managed by the commerce source) or 'operations' (managed in Arasya). */
        public string $productionAuthority = 'source',
        /** Advance payment facts stated by the source ('cod'/'online' and the advance status); null when unstated. */
        public ?string $paymentMethod = null,
        public ?string $advanceStatus = null,
        public ?string $paymentReversedAt = null,
        /**
         * Active rework tracks (migration 028): pieces of this order that travel separately, each with its stage and
         * owner. Empty for every order whose pieces all travel together; the fields above describe those pieces.
         * @var list<array{trackUuid: string, stageId: string, ownerEmployeeUuid: ?string}>
         */
        public array $activeTracks = [],
        /**
         * API 2.32.0: why the order may not be packed or delivered yet although it is at packing or delivery
         * (PieceGate: pieces_in_rework, pieces_awaiting_reunion, plan_confirmation_required, pieces_incomplete,
         * route_incomplete); null when nothing blocks it or the order is not at a gated stage.
         */
        public ?string $pieceGateReason = null,
        /**
         * API 2.37.0: the Confirmare workflow cohort decided when Arasya created the order (WorkflowCohort; null for
         * every order created before) and the order's Confirmare decision (order_confirmations.status, or null).
         */
        public ?string $workflowCohort = null,
        public ?string $confirmationStatus = null,
        /** API 2.38.0: order_confirmations.production_cleared (ConfirmationClearance), or null without a Confirmare row. */
        public ?bool $confirmationCleared = null,
    ) {
    }

    /** Whether some pieces are away from the order in rework (its stage is then not the stage of every piece). */
    public function hasActiveTracks(): bool
    {
        return $this->activeTracks !== [];
    }

    /** A stale or revoked production document blocks every production action until a new revision is active. */
    public function isBlockedByDocument(): bool
    {
        return \Arasya\Operations\Document\DocumentGuard::isBlocked($this->documentStatus);
    }

    /** An open production exception (for example a cutting fault return) blocks every normal production action. */
    public function isBlockedByException(): bool
    {
        return $this->openExceptionUuid !== null;
    }

    public function isProductionCompleted(): bool
    {
        return $this->productionCompletedAt !== null;
    }

    /** Staff-facing order status; production completion is represented as handed over. */
    public function staffStatus(): string
    {
        if ($this->operationalStatus === 'unavailable') {
            return 'unavailable';
        }
        return $this->isProductionCompleted() ? 'handed_over' : $this->operationalStatus;
    }

    public function totalMeters(): ?float
    {
        $total = null;
        foreach ($this->items as $item) {
            if ($item->meters !== null) {
                $total = ($total ?? 0.0) + $item->meters;
            }
        }
        return $total;
    }
}
