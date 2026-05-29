<?php

namespace App\Services\UserAgreements;

/**
 * One row per reconciliation pass over a single user. Mutated in
 * place by the Reconciler; the artisan command aggregates these to
 * produce a human-readable summary.
 */
class ReconciliationReport
{
    public int $plannedPickup     = 0;
    public int $createdPickup     = 0;
    public int $plannedUpgrade    = 0;
    public int $createdUpgrade    = 0;
    public int $plannedPurchase   = 0;
    public int $createdPurchase   = 0;
    public int $plannedStatusFlip = 0;
    public int $statusFlipped     = 0;

    /** @var array<int, int> */
    public array $createdRowIds = [];

    public function __construct(public readonly int $userId)
    {
    }

    public function hasChanges(): bool
    {
        return $this->createdPickup
            + $this->createdUpgrade
            + $this->createdPurchase
            + $this->statusFlipped
            > 0;
    }

    public function hasPlans(): bool
    {
        return $this->plannedPickup
            + $this->plannedUpgrade
            + $this->plannedPurchase
            + $this->plannedStatusFlip
            > 0;
    }
}
